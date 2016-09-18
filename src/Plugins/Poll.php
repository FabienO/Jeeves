<?php declare(strict_types=1);

namespace Room11\Jeeves\Plugins;

use Room11\Jeeves\Chat\Client\ChatClient;
use Room11\Jeeves\Chat\Message\Command;
use Room11\Jeeves\Chat\Client\Chars;
use Room11\Jeeves\Chat\Client\PostFlags;
use Room11\Jeeves\System\PluginCommandEndpoint;
use Room11\Jeeves\Storage\KeyValue as KeyValueStore;

class Poll extends BasePlugin
{
    private $chatClient;

    public function __construct(ChatClient $chatClient, KeyValueStore $storage, PollManager $pollManager)
    {
        $this->chatClient   = $chatClient;
        $this->storage      = $storage;
        $this->pollManager  = $pollManager;
    }

    public function getDescription(): string
    {
        return 'Create and vote on polls.';
    }

    public function commandHandler(Command $command)
    {
        $params = '';
        if (count($command->getParameters()) > 1) {
            $params = trim(substr($command->getText(), strpos($command->getText(), ' ')));
        }

        switch($command->getParameter(0)) {
            case 'add':
                return yield from $this->pollManager->createPoll($params, $command);
            case 'del':
                return yield from $this->pollManager->deletePoll(md5(strtolower($params)), strtolower($command->getParameter(1)), $command);
            case 'get':
                return yield from $this->pollManager->getPoll(md5(strtolower($params)), $command);
            case 'list':
                return yield from $this->pollManager->getPolls($command);
            case 'vote':
                return yield from $this->pollManager->votePoll($command);
            default:
                return 'Invalid poll request format. Git gud scrub.';
        }
    }

    /**
     * @return PluginCommandEndpoint[]
     */
    public function getCommandEndpoints(): array
    {
        return [new PluginCommandEndpoint('Poll', [$this, 'commandHandler'], 'poll')];
    }
}

class PollManager {
    CONST RESPONSES = [
        0 => 'Invalid Poll format. Example: {"title": "Asahi", "question": "Is Asahi nice?", "options": ["Yum", "Taste like butts"]}',
        1 => 'Title of the poll is too long.',
        2 => 'Poll with that title already exists.',
        3 => 'Poll created.',
        4 => 'Invalid format. Example: !!poll vote <pole name> <answer id>',
        5 => 'You\'ve already voted on this poll you scallywag!',
        6 => 'Couldn\'t find a matching answer. Dispatching dogs that shoot bees out their mouths when they bark.',
        7 => 'Vote added.',
        8 => 'Poll does not exist',
        9 => 'No polls active',
        10 => 'Poll does not exist',
        11 => 'You don\'t own this poll. Stranger danger!',
        12 => 'Poll deleted',
        13 => 'Error removing poll. Blame PeeHaa'
    ];

    public function __construct(
        ChatClient $chatClient,
        KeyValueStore $storage,
        PollValidator $pollValidator
    ) {
        $this->chatClient       = $chatClient;
        $this->storage          = $storage;
        $this->pollValidator    = $pollValidator;
    }

    public function createPoll(string $pollInfo, Command $command): \Generator
    {
        $pollInfo = json_decode($pollInfo, true);

        $pollValidation = $this->pollValidator->validatePoll($pollInfo);
        if ($pollValidation !== true) {
            return yield $this->chatClient->postMessage($command->getRoom(), self::RESPONSES[$pollValidation]);
        }

        if (true === yield $this->storage->exists(md5(strtolower($pollInfo['title'])), $command->getRoom())) {
            return yield $this->chatClient->postMessage($command->getRoom(), self::RESPONSES[2]);
        }

        $modifiedOptions = [];
        array_walk($pollInfo['options'], function($v) use (&$modifiedOptions) {
            $modifiedOptions[] = ['answer' => $v, 'score' => 0];
        });

        $poll = new PollData();
        $poll->key          = md5(trim(strtolower($pollInfo['title'])));
        $poll->title        = $pollInfo['title'];
        $poll->options      = $modifiedOptions;
        $poll->question     = $pollInfo['question'];

        print_r($poll);

        $this->storage->set($poll->key, (array) $poll, $command->getRoom());
        yield from $this->updatePollList($poll, $command);

        yield $this->chatClient->postMessage($command->getRoom(), $poll->title . ' - ' . self::RESPONSES[3]);
    }

    private function updatePollList(PollData $poll, Command $command)
    {
        $newPollList    = false;
        $newPoll        = true;

        $pollCreator = [
            'title'     => $poll->title,
            'uid'       => $command->getUserId(),
            'username'  => $command->getUserName(),
            'question'  => $poll->question
        ];

        if (false === yield $this->storage->exists('pollList', $command->getRoom())) {
            // New poll list
            $newPollList = true;
            $this->storage->set('pollList', [$pollCreator], $command->getRoom());
        }

        if ($newPollList === false)  {
            $polls = yield $this->storage->get('pollList', $command->getRoom());

            foreach ($polls as $p) {
                if ($p['title'] == $poll->title) {
                    $newPoll = false;
                }
            }

            if ($newPoll === true) {
                $polls[] = $pollCreator;
                $this->storage->set('pollList', $polls, $command->getRoom());
            }
        }

        return true;
    }

    public function votePoll(Command $command): \Generator
    {
        $matches = [];
        preg_match('/(.+)\s+(\d+)/', trim(str_replace('vote ', '', $command->getText())), $matches);

        $pollName       = strtolower($matches[1]) ?? 'missing';
        $voteAnswerId   = $matches[2] ?? 'missing';

        $validationResult = $this->pollValidator->validateVote($pollName, $voteAnswerId, $command);
        if ($validationResult !== true) {
            return yield $this->chatClient->postMessage($command->getRoom(), self::RESPONSES[$validationResult]);
        }

        $voteAnswerId = ($voteAnswerId - 1); // To account for index starting at 0
        $answerFound = false;
        if (in_array($command->getUserId(), yield from $this->getRecordedVotes($pollName, $command))) {
            return yield $this->chatClient->postMessage($command->getRoom(), self::RESPONSES[5]);
        }

        $poll = yield $this->storage->get(md5($pollName), $command->getRoom());
        array_walk($poll['options'], function(&$v, $k) use ($voteAnswerId, &$answerFound) {
            if ($k == $voteAnswerId) {
                $v['score']++;
                $answerFound = true;
            }
        });

        if ($answerFound == false) {
            return yield $this->chatClient->postMessage($command->getRoom(), $command->getUserName() . ' ' . self::RESPONSES[6]);
        }

        $recordedVotes[] = $command->getUserId();
        $this->storage->set($pollName.'-votes', $recordedVotes, $command->getRoom());
        $this->storage->set($poll['key'], $poll, $command->getRoom());

        yield $this->chatClient->postMessage($command->getRoom(), $command->getUserName() . ' ' . self::RESPONSES[7]);
    }

    private function getRecordedVotes($title, Command $command)
    {
        if (false === yield $this->storage->exists($title . '-votes', $command->getRoom())) {
            return [];
        }

        return yield $this->storage->get($title . '-votes', $command->getRoom());
    }

    public function getPoll($pollKey, Command $command): \Generator
    {
        if (false === yield $this->storage->exists(strtolower($pollKey), $command->getRoom())) {
            return yield $this->chatClient->postMessage($command->getRoom(), self::RESPONSES[8]);
        }

        $poll = yield $this->storage->get($pollKey, $command->getRoom());

        yield $this->chatClient->postMessage($command->getRoom(), $poll['question']);
        yield $this->chatClient->postMessage($command->getRoom(), $this->formatResult($poll), PostFlags::FIXED_FONT);
    }

    private function formatResult(array $poll) : string
    {
        $result = '';
        $maxScore = 0;
        foreach ($poll['options'] as $k => $option) {
            if ($option['score'] > $maxScore) {
                $maxScore = $option['score'];
            }
        }
        foreach ($poll['options'] as $k => $option) {
            $result .= ($k+1) .  " | ". str_pad('', $option['score'], '#', STR_PAD_RIGHT);
            $result .= str_pad('', ($maxScore - $option['score']), ' ', STR_PAD_LEFT);
            $result .= ' (' . $option['score'] . ') ';

            $result .= $option['score'] < 10
            ? ' '
            : '';

            $result .= strlen($option['answer']) < 50
                ? $option['answer']
                : substr($option['answer'], 50) . '...';

            $result .= "\n";
        }

        return $result;
    }

    public function getPolls(Command $command): \Generator
    {
        $result = '';
        if (false === yield $this->storage->exists('pollList', $command->getRoom())) {
            return yield $this->chatClient->postMessage($command->getRoom(), self::RESPONSES[9]);
        }

        $pollList = yield $this->storage->get('pollList', $command->getRoom());
        foreach ($pollList as $poll) {
            $result .= Chars::BULLET . " " . $poll['title'] . " - " . $poll['question'] . "\n";
        }

        yield $this->chatClient->postMessage($command->getRoom(), $result);
    }

    public function deletePoll($pollKey, $title, Command $command): \Generator
    {
        if (false === yield $this->storage->exists(strtolower($pollKey), $command->getRoom())) {
            return yield $this->chatClient->postMessage($command->getRoom(), $title . " - " . self::RESPONSES[10]);
        }

        $polls = yield $this->storage->get('pollList', $command->getRoom());
        foreach ($polls as $poll) {
            if (strtolower($poll['title']) == $title && $command->getUserId() !== $poll['uid']) {
                return yield $this->chatClient->postMessage($command->getRoom(), $title . " - " . self::RESPONSES[11]);
            }
        }

        if (yield $this->storage->unset($pollKey, $command->getRoom())) {
            foreach ($polls as $k => $poll) {
                if (strtolower($poll['title']) == $title) {
                    unset($polls[$k]);
                }
            }

            if (empty(array_filter($polls))) {
                $this->storage->unset('pollList', $command->getRoom());
            } else {
                $this->storage->set('pollList', $polls, $command->getRoom());
            }

            $this->storage->unset($title . '-votes', $command->getRoom());

            return yield $this->chatClient->postMessage($command->getRoom(), $title . ' ' .self::RESPONSES[12]);
        }

        yield $this->chatClient->postMessage($command->getRoom(), $title . ' ' . self::RESPONSES[12]);
    }
}

class PollData {
    public $key;
    public $title;
    public $question;
    public $options;
}

class PollVote {
    public $title;
    public $vote;
}

class PollValidator {
    public function validateVote($pollName, $voteAnswerId)
    {
        if (
            empty($pollName)
            || empty($voteAnswerId)
            || !is_numeric($voteAnswerId)
            || is_numeric($pollName)
        ) {
            return 4;
        }

        return true;
    }

    public function validatePoll($poll)
    {
        if (
            $poll == null
            || $poll == false
            || !is_array($poll)
            || empty($poll['title'])
            || empty($poll['question'])
            || empty($poll['options'])
        ) {
            return 0;
        }

        if (strlen($poll['title']) > 30) {
            return 1;
        }

        return true;
    }
}