<?php

namespace AppBundle\Entity;

class Log
{
    protected $id;

    protected $eventName;

    protected $message;

    protected $parameters = [];

    protected $createdAt;

    public function getId()
    {
        return $this->id;
    }

    public function getEventName()
    {
        return $this->eventName;
    }

    public function setEventName($eventName)
    {
        $this->eventName = $eventName;

        return $this;
    }

    public function getMessage()
    {
        return $this->message;
    }

    public function setMessage($message)
    {
        $this->message = $message;

        return $this;
    }

    public function getParameters()
    {
        return $this->parameters;
    }

    public function setParameters($parameters)
    {
        $this->parameters = $parameters;

        return $this;
    }

    public function getCreatedAt()
    {
        return $this->createdAt;
    }
}
