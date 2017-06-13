<?php
declare(strict_types=1);

namespace TSwiackiewicz\AwesomeApp\DomainModel\User;

use TSwiackiewicz\AwesomeApp\DomainModel\User\Event\{
    UserActivatedEvent, UserDisabledEvent, UserEnabledEvent, UserEvent, UserPasswordChangedEvent, UserRegisteredEvent, UserUnregisteredEvent
};
use TSwiackiewicz\AwesomeApp\DomainModel\User\Exception\PasswordException;
use TSwiackiewicz\AwesomeApp\DomainModel\User\Exception\UserException;
use TSwiackiewicz\AwesomeApp\DomainModel\User\Password\UserPassword;
use TSwiackiewicz\AwesomeApp\SharedKernel\User\{
    Exception\InvalidArgumentException, UserId
};
use TSwiackiewicz\DDD\Event\EventBus;
use TSwiackiewicz\DDD\EventSourcing\AggregateHistory;

/**
 * Class User
 * @package TSwiackiewicz\AwesomeApp\DomainModel\User
 */
class User
{
    /**
     * @var UserId
     */
    private $id;

    /**
     * @var UserLogin
     */
    private $login;

    /**
     * @var UserPassword
     */
    private $password;

    /**
     * @var bool
     */
    private $active;

    /**
     * @var bool
     */
    private $enabled;

    /**
     * User constructor.
     * @param UserId $id
     */
    private function __construct(UserId $id)
    {
        $this->id = $id;
    }

    /**
     * @param AggregateHistory $aggregateHistory
     * @return User
     * @throws InvalidArgumentException
     */
    public static function reconstituteFrom(AggregateHistory $aggregateHistory): User
    {
        $aggregateId = $aggregateHistory->getAggregateId();
        /** @var UserId $userId */
        $userId = UserId::fromString($aggregateId->getAggregateId())->setId($aggregateId->getId());

        $user = new static($userId);

        /** @var UserEvent[] $events */
        $events = $aggregateHistory->getDomainEvents();
        foreach ($events as $event) {
            $user->apply($event);
        }

        return $user;
    }

    /**
     * TODO: move to "DDD Framework"
     *
     * @param UserEvent $event
     */
    private function apply(UserEvent $event): void
    {
        $classParts = explode('\\', get_class($event));

        $method = 'when' . end($classParts);
        if ('Event' === substr($method, -5)) {
            $method = substr($method, 0, -5);
        }

        if (method_exists($this, $method)) {
            $this->$method($event);
        }
    }

    /**
     * Register new user
     *
     * @param UserId $id
     * @param UserLogin $username
     * @param UserPassword $password
     * @return User
     */
    public static function register(UserId $id, UserLogin $username, UserPassword $password): User
    {
        $user = new static($id);

        $user->recordThat(
            new UserRegisteredEvent($id, (string)$username, (string)$password, $user->doHash((string)$username))
        );

        return $user;
    }

    /**
     * @param UserEvent $event
     */
    private function recordThat(UserEvent $event): void
    {
        $this->apply($event);
        EventBus::publish($event);
    }

    /**
     * @param string $login
     * @return string
     */
    private function doHash(string $login): string
    {
        $hash = md5('::' . $login . '::');

        // salt added to User's hash
        return substr($hash, 0, 8) .
            substr($hash, 24, 8) .
            substr($hash, 16, 8) .
            substr($hash, 8, 8);
    }

    /**
     * Activate user
     *
     * @throws UserException
     */
    public function activate(): void
    {
        if ($this->active) {
            throw UserException::alreadyActivated($this->id);
        }

        $this->recordThat(
            new UserActivatedEvent($this->id)
        );
    }

    /**
     * Enable user
     *
     * @throws UserException
     */
    public function enable(): void
    {
        if (!$this->active || $this->enabled) {
            throw UserException::enableNotAllowed($this->id);
        }

        $this->recordThat(
            new UserEnabledEvent($this->id)
        );
    }

    /**
     * Disable user
     *
     * @throws UserException
     */
    public function disable(): void
    {
        if (!$this->active || !$this->enabled) {
            throw UserException::disableNotAllowed($this->id);
        }

        $this->recordThat(
            new UserDisabledEvent($this->id)
        );
    }

    /**
     * Change user's password
     *
     * @param UserPassword $password
     * @throws UserException
     * @throws PasswordException
     */
    public function changePassword(UserPassword $password): void
    {
        if (!$this->active || !$this->enabled) {
            throw UserException::passwordChangeNotAllowed($this->id);
        }

        if ($this->password->equals($password)) {
            throw PasswordException::newPasswordEqualsWithCurrentPassword($this->id);
        }

        $this->recordThat(
            new UserPasswordChangedEvent($this->id, (string)$password)
        );
    }

    /**
     * Unregister user
     */
    public function unregister(): void
    {
        $this->recordThat(
            new UserUnregisteredEvent($this->id)
        );
    }

    /**
     * @return UserId
     */
    public function getId(): UserId
    {
        return $this->id;
    }

    /**
     * Generate user hash string
     *
     * @return string
     */
    public function hash(): string
    {
        return $this->doHash((string)$this->login);
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    /**
     * @param UserRegisteredEvent $event
     */
    private function whenUserRegistered(UserRegisteredEvent $event): void
    {
        $this->login = new UserLogin($event->getLogin());
        $this->password = new UserPassword($event->getPassword());
        $this->active = $event->isActive();
        $this->enabled = $event->isEnabled();
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    /**
     * @param UserActivatedEvent $event
     */
    private function whenUserActivated(UserActivatedEvent $event): void
    {
        $this->active = $event->isActive();
        $this->enabled = $event->isEnabled();
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    /**
     * @param UserEnabledEvent $event
     */
    private function whenUserEnabled(UserEnabledEvent $event): void
    {
        $this->enabled = $event->isEnabled();
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    /**
     * @param UserDisabledEvent $event
     */
    private function whenUserDisabled(UserDisabledEvent $event): void
    {
        $this->enabled = $event->isEnabled();
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    /**
     * @param UserPasswordChangedEvent $event
     */
    private function whenUserPasswordChanged(UserPasswordChangedEvent $event): void
    {
        $this->password = new UserPassword($event->getPassword());
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    /**
     * @param UserUnregisteredEvent $event
     */
    private function whenUserUnregistered(UserUnregisteredEvent $event): void
    {
        $this->active = $event->isActive();
        $this->enabled = $event->isEnabled();
    }
}