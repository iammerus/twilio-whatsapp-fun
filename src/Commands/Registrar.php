<?php

namespace Merus\WAB\Commands;


use Exception;
use InvalidArgumentException;
use Merus\WAB\Commands\Defaults\FallbackCommand;
use Merus\WAB\Commands\Defaults\SessionExpiryCommand;
use Merus\WAB\Commands\Defaults\UserInformationCommand;
use Merus\WAB\Commands\Interfaces\CommandInterface;
use Merus\WAB\Database\DB;
use Merus\WAB\ExecutionResult;
use Merus\WAB\Helpers\Log;
use Merus\WAB\Http\TwilioRequest;

class Registrar
{
    protected array $registered = [];

    protected DB $db;

    protected ExecutionResult $exec;

    /**
     * @var UserInformationCommand
     */
    private ?CommandInterface $current;

    /**
     * @var mixed
     */
    private ?string $active;

    /**
     * How many minutes does it take for a session to expire
     * @var int
     */
    private int $expiry = 30;

    public function __construct(DB $db)
    {
        $this->db = $db;
        $this->exec = new ExecutionResult($this->db);
    }

    /**
     * Execute a command
     *
     * @param User $user The current request's user
     * @param string $input The current user's message
     *
     * @return array
     */
    public function execute(User $user, string $input)
    {
        return $this->current->execute(new Context($user, $input, $this->db, $this->exec));
    }

    /**
     * Define a new command
     *
     * @param array $meta
     * @param string $command
     * @param bool $isFallback
     *
     * @throws Exception
     */
    public function define(array $meta, string $command, bool $isFallback)
    {
        if (array_key_exists($meta['key'], $this->registered)) {
            throw new Exception("Command with meta {$meta['key']} has been registered already");
        }

        $this->registered[$meta['key']] = [
            'class' => $command,
            'meta' => $meta,
            'fallback' => $isFallback
        ];
    }

    /**
     * @throws Exception
     */
    public function register()
    {
        $this->define(FallbackCommand::meta(), FallbackCommand::class, true);
        $this->define(SessionExpiryCommand::meta(), SessionExpiryCommand::class, false);

        // Load external commands
        $externals = require_once ROOT_PATH . '/commands.php';

        foreach ($externals as $external) {
            $meta = $external::meta();

            $this->define($meta, $external, $meta['fallback'] ?? false);
        }

        // Get the total number of registered commands
        $total = count($this->registered);

        // Log out entry
        Log::get()->logWrite("Finished registering commands. Total commands in registry: {$total}. ", false);
    }

    /**
     * Handle an incoming message to the app
     *
     * @param TwilioRequest $request
     * @throws Exception
     */
    public function request(TwilioRequest $request)
    {
        $user = $this->fetchUser($request->getFrom());
        $result = null;

        if (!is_string($user->getName()) || strlen($user->getName()) === 0) {
            $this->current = new UserInformationCommand();
            $this->active = UserInformationCommand::meta()['key'];

            $result = $this->execute($user, $request->getBody());
        } else {
            $result = $this->match($user, $request->getBody());


            if (!$result['command'] || !is_array($result['command'])) throw new Exception("Failed to match request to any command");

            /**
             * @var CommandInterface $command
             */
            $command = new $result['command']['class']();

            $this->active = $result['command']['meta']['key'];
            $this->current = $command;

            $result = $this->execute($user, $request->getBody());
        }

        $this->exec->create($this->active, $result, $user);
    }


    protected function fetchUser($phone): User
    {
        $user = $this->db->row("SELECT * FROM `users` WHERE `uid` = '{$phone}'");

        if (!$user) {
            $user = $this->createUser($phone);
        }

        return User::fromObject($user);
    }

    protected function match(User $user, string $input): array
    {
        // Let's start by fetching the last execution result for the current user, if any
        // If there is a last execution result that's still pending, and it's not expired, then pipe the
        // request to that command
        $last = $this->exec->last($user);

        if ($last && $last->result !== COMMAND_EXECUTION_COMPLETE) {
            $minutes = $this->elapsed($last->last_updated);

            if ($minutes > $this->expiry) {
                return [
                    'command' => $this->registered['default.expired'],
                    'last' => $last
                ];
            } else {
                return [
                    'command' => $this->registered[$last->command],
                    'last' => $last
                ];
            }
        }

        // So, it was neither an expired session nor an incomplete command.
        foreach ($this->registered as $command) {
            // Check if we're not on a fallback command
            if (array_key_exists('fallback', $command) && $command['fallback']) continue;

            // Check if we don't have a match pattern for the current command
            if (!array_key_exists('match', $command['meta'])) continue;

            // Check if this is a programmatically invoked command (match is null)
            if ($command['meta']['match'] === null) continue;

            $pattern = $command['meta']['match'];

            // TODO: Implement support for array of patterns to be passed in as a parameter
            // We currently don't support multiple patterns per command
            if (is_array($pattern)) break;

            if (preg_match("#{$pattern}#i", $input)) {
                return [
                    'command' => $command,
                    'last' => null
                ];
            }
        }

        return [
            'command' => $this->registered['default.fallback'],
            'last' => null
        ];
    }

    /**
     * Get how many minutes it takes for a session to expire
     *
     * @param string $old The tie to compare to the current time
     * @return int
     */
    protected function elapsed(string $old)
    {
        $first = strtotime($old);

        $seconds = time() - $first;

        return (int)($seconds / 60);
    }

    protected function createUser($phone)
    {
        $result = $this->db->insert('users', [
            'uid' => $phone
        ]);

        if (!is_string($result)) {
            throw new InvalidArgumentException("Failed to create a user record for the specified user");
        }

        return $this->db->row("SELECT * FROM `users` WHERE `id` = {$result}");
    }
}