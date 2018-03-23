<?php

namespace AppBundle\EventSubscriber;

use AppBundle\Entity\Log;
use AppBundle\Event;
use Doctrine\Bundle\DoctrineBundle\Registry as DoctrineRegistry;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class LogSubscriber implements EventSubscriberInterface
{
    private $doctrine;
    private $tokenStorage;

    public function __construct(DoctrineRegistry $doctrine, TokenStorageInterface $tokenStorage)
    {
        $this->doctrine = $doctrine;
        $this->tokenStorage = $tokenStorage;
    }

    public static function getSubscribedEvents()
    {
        return [
            Event\TaskAssignEvent::NAME => 'onTaskAssign',
            Event\TaskCreateEvent::NAME => 'onTaskCreate',
            Event\TaskFailedEvent::NAME => 'onTaskFailed',
            Event\TaskDoneEvent::NAME   => 'onTaskDone',
            // TaskUnassignEvent::NAME => 'onTaskUnassign',
        ];
    }

    private function getUser()
    {
        if ($token = $this->tokenStorage->getToken()) {
            if ($user = $token->getUser()) {
                return $user;
            }
        }
    }

    private function persistLog($eventName, array $parameters = [])
    {
        if (null === $this->getUser()) {
            return;
        }

        $parameters = array_merge($parameters, [
            '%user%' => $this->getUser()->getUsername(),
        ]);

        $log = new Log();
        $log->setEventName($eventName);
        $log->setMessage($eventName);
        $log->setParameters($parameters);

        $this->doctrine
            ->getManagerForClass(Log::class)
            ->persist($log);

        $this->doctrine
            ->getManagerForClass(Log::class)
            ->flush();
    }

    public function onTaskAssign(Event\TaskAssignEvent $event)
    {
        $this->persistLog(Event\TaskAssignEvent::NAME, [
            '%task%' => $event->getTask()->getId(),
            '%task_user%' => $event->getUser()->getUsername()
        ]);
    }

    public function onTaskCreate(Event\TaskCreateEvent $event)
    {
        $this->persistLog(Event\TaskCreateEvent::NAME, [
            '%task%' => $event->getTask()->getId(),
        ]);
    }

    public function onTaskFailed(Event\TaskFailedEvent $event)
    {
        $this->persistLog(Event\TaskFailedEvent::NAME, [
            '%task%' => $event->getTask()->getId(),
        ]);
    }

    public function onTaskDone(Event\TaskDoneEvent $event)
    {
        $this->persistLog(Event\TaskDoneEvent::NAME, [
            '%task%' => $event->getTask()->getId(),
        ]);
    }
}
