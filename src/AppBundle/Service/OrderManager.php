<?php

namespace AppBundle\Service;

use AppBundle\Entity\Delivery;
use AppBundle\Entity\Task;
use AppBundle\Event\OrderCancelEvent;
use AppBundle\Event\OrderCreateEvent;
use AppBundle\Event\OrderAcceptEvent;
use AppBundle\Event\OrderFullfillEvent;
use AppBundle\Event\OrderReadyEvent;
use AppBundle\Event\OrderRefuseEvent;
use AppBundle\Event\PaymentAuthorizeEvent;
use AppBundle\Sylius\Order\OrderTransitions;
use SM\Factory\FactoryInterface as StateMachineFactoryInterface;
use Sylius\Component\Order\Model\OrderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class OrderManager
{
    private $routing;
    private $stateMachineFactory;
    private $eventDispatcher;

    public function __construct(
        RoutingInterface $routing,
        StateMachineFactoryInterface $stateMachineFactory,
        EventDispatcherInterface $eventDispatcher)
    {
        $this->routing = $routing;
        $this->stateMachineFactory = $stateMachineFactory;
        $this->eventDispatcher = $eventDispatcher;
    }

    public function create(OrderInterface $order)
    {
        $stateMachine = $this->stateMachineFactory->get($order, OrderTransitions::GRAPH);
        $stateMachine->apply(OrderTransitions::TRANSITION_CREATE);

        // Cascade create transition
        foreach ($order->getPayments() as $payment) {
            // Use $soft = true, do nothing if transition can't be applied
            $this->stateMachineFactory
                ->get($payment, PaymentTransitions::GRAPH)
                ->apply(PaymentTransitions::TRANSITION_CREATE, $soft = true);
        }

        $this->dispatchEvent($order, OrderCreateEvent::NAME);
    }

    public function accept(OrderInterface $order)
    {
        $stateMachine = $this->stateMachineFactory->get($order, OrderTransitions::GRAPH);
        $stateMachine->apply(OrderTransitions::TRANSITION_ACCEPT);

        $this->createDelivery($order);

        $this->dispatchEvent($order, OrderAcceptEvent::NAME);
    }

    public function refuse(OrderInterface $order)
    {
        $stateMachine = $this->stateMachineFactory->get($order, OrderTransitions::GRAPH);
        $stateMachine->apply(OrderTransitions::TRANSITION_REFUSE);

        $this->dispatchEvent($order, OrderRefuseEvent::NAME);
    }

    public function ready(OrderInterface $order)
    {
        $stateMachine = $this->stateMachineFactory->get($order, OrderTransitions::GRAPH);
        $stateMachine->apply(OrderTransitions::TRANSITION_READY);

        $this->dispatchEvent($order, OrderReadyEvent::NAME);
    }

    public function fulfill(OrderInterface $order)
    {
        $stateMachine = $this->stateMachineFactory->get($order, OrderTransitions::GRAPH);
        $stateMachine->apply(OrderTransitions::TRANSITION_FULFILL);

        $this->dispatchEvent($order, OrderFullfillEvent::NAME);
    }

    public function cancel(OrderInterface $order)
    {
        $stateMachine = $this->stateMachineFactory->get($order, OrderTransitions::GRAPH);
        $stateMachine->apply(OrderTransitions::TRANSITION_CANCEL);

        $this->dispatchEvent($order, OrderCancelEvent::NAME);
    }

    private function createDelivery(OrderInterface $order)
    {
        if (null !== $order->getDelivery()) {
            return;
        }

        $pickupAddress = $order->getRestaurant()->getAddress();
        $dropoffAddress = $order->getShippingAddress();

        $duration = $this->routing->getDuration(
            $pickupAddress->getGeo(),
            $dropoffAddress->getGeo()
        );

        $dropoffDoneBefore = $order->getShippedAt();

        $pickupDoneBefore = clone $dropoffDoneBefore;
        $pickupDoneBefore->modify(sprintf('-%d seconds', $duration));

        $pickup = new Task();
        $pickup->setType(Task::TYPE_PICKUP);
        $pickup->setAddress($pickupAddress);
        $pickup->setDoneBefore($pickupDoneBefore);

        $dropoff = new Task();
        $dropoff->setType(Task::TYPE_DROPOFF);
        $dropoff->setAddress($dropoffAddress);
        $dropoff->setDoneBefore($dropoffDoneBefore);

        $delivery = new Delivery();
        $delivery->addTask($pickup);
        $delivery->addTask($dropoff);

        $order->setDelivery($delivery);
    }

    private function dispatchEvent(OrderInterface $order, $eventName)
    {
        switch ($eventName) {
            case OrderCancelEvent::NAME:
                $this->eventDispatcher->dispatch(OrderCancelEvent::NAME, new OrderCancelEvent($order));
                break;
            case OrderCreateEvent::NAME:
                $this->eventDispatcher->dispatch(OrderCreateEvent::NAME, new OrderCreateEvent($order));
                break;
            case OrderRefuseEvent::NAME:
                $this->eventDispatcher->dispatch(OrderRefuseEvent::NAME, new OrderRefuseEvent($order));
                break;
            case OrderAcceptEvent::NAME:
                $this->eventDispatcher->dispatch(OrderAcceptEvent::NAME, new OrderAcceptEvent($order));
                break;
            case OrderReadyEvent::NAME:
                $this->eventDispatcher->dispatch(OrderReadyEvent::NAME, new OrderReadyEvent($order));
                break;
            case OrderFullfillEvent::NAME:
                $this->eventDispatcher->dispatch(OrderFullfillEvent::NAME, new OrderFullfillEvent($order));
                break;
        }
    }
}
