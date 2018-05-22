<?php

namespace Tests\AppBundle\Service;

use AppBundle\Entity\Address;
use AppBundle\Entity\Base\GeoCoordinates;
use AppBundle\Entity\Delivery;
use AppBundle\Entity\Restaurant;
use AppBundle\Entity\StripePayment;
use AppBundle\Entity\Sylius\Order;
use AppBundle\Event;
use AppBundle\Service\OrderManager;
use AppBundle\Service\RoutingInterface;
use AppBundle\Sylius\Order\OrderInterface;
use Prophecy\Argument;
use SM\StateMachine\StateMachineInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Sylius\Component\Payment\Model\PaymentInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class OrderManagerTest extends KernelTestCase
{
    private $orderManager;

    public function setUp()
    {
        parent::setUp();

        self::bootKernel();

        $this->stateMachineFactory = static::$kernel->getContainer()->get('sm.factory');

        $this->routing = $this->prophesize(RoutingInterface::class);
        $this->eventDispatcher = $this->prophesize(EventDispatcherInterface::class);

        $this->orderManager = new OrderManager(
            $this->routing->reveal(),
            $this->stateMachineFactory,
            $this->eventDispatcher->reveal()
        );
    }

    public function testCreateDoesNothing()
    {
        $delivery = new Delivery();

        $order = new Order();
        $order->setDelivery($delivery);

        $this->orderManager->create($order);

        $this->assertSame($delivery, $order->getDelivery());
    }

    public function testCreateCascadesTransition()
    {
        $stripePayment = new StripePayment();
        $stripePayment->setState(PaymentInterface::STATE_CART);

        $order = new Order();
        $order->setState(OrderInterface::STATE_CART);
        $order->addPayment($stripePayment);

        $this->eventDispatcher
            ->dispatch(Event\OrderCreateEvent::NAME, Argument::type(Event\OrderCreateEvent::class))
            ->shouldBeCalled();

        $this->orderManager->create($order);

        $this->assertEquals(OrderInterface::STATE_NEW, $order->getState());
        $this->assertEquals(PaymentInterface::STATE_NEW, $stripePayment->getState());
    }

    public function testAcceptCreatesDelivery()
    {
        $pickupAddress = new Address();
        $pickupAddress->setGeo(new GeoCoordinates());

        $dropoffAddress = new Address();
        $dropoffAddress->setGeo(new GeoCoordinates());

        $restaurant = new Restaurant();
        $restaurant->setAddress($pickupAddress);

        $order = new Order();
        $order->setState(OrderInterface::STATE_NEW);
        $order->setRestaurant($restaurant);
        $order->setShippingAddress($dropoffAddress);
        $order->setShippedAt(new \DateTime('+1 hour'));

        $this->routing
            ->getDuration(
                Argument::type(GeoCoordinates::class),
                Argument::type(GeoCoordinates::class)
            )
            ->willReturn(600);

        $this->eventDispatcher
            ->dispatch(Event\OrderAcceptEvent::NAME, Argument::type(Event\OrderAcceptEvent::class))
            ->shouldBeCalled();

        $this->assertNull($order->getDelivery());

        $this->orderManager->accept($order);

        $this->assertEquals(OrderInterface::STATE_ACCEPTED, $order->getState());
        $this->assertNotNull($order->getDelivery());
    }
}
