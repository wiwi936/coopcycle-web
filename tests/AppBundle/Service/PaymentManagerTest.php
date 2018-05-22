<?php

namespace Tests\AppBundle\Service;

use AppBundle\Entity\Delivery;
use AppBundle\Entity\Restaurant;
use AppBundle\Entity\StripeAccount;
use AppBundle\Entity\StripePayment;
use AppBundle\Entity\StripeTransfer;
use AppBundle\Event\PaymentAuthorizeEvent;
use AppBundle\Service\PaymentManager;
use AppBundle\Service\RoutingInterface;
use AppBundle\Service\SettingsManager;
use AppBundle\Sylius\Order\OrderInterface;
use AppBundle\Sylius\StripeTransfer\StripeTransferTransitions;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use SM\Factory\FactoryInterface as StateMachineFactoryInterface;
use SM\StateMachine\StateMachineInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Tests\AppBundle\StripeTrait;

class PaymentManagerTest extends KernelTestCase
{
    use StripeTrait {
        setUp as setUpStripe;
    }

    private $stateMachineFactory;
    private $settingsManager;
    private $eventDispatcher;

    private $paymentManager;

    public function setUp()
    {
        parent::setUp();

        self::bootKernel();

        $this->setUpStripe();

        $this->stateMachineFactory = static::$kernel->getContainer()->get('sm.factory');
        $this->settingsManager = $this->prophesize(SettingsManager::class);
        $this->eventDispatcher = $this->prophesize(EventDispatcherInterface::class);

        $this->settingsManager
            ->get('stripe_secret_key')
            ->willReturn(self::$stripeApiKey);

        $this->paymentManager = new PaymentManager(
            $this->stateMachineFactory,
            $this->settingsManager->reveal(),
            $this->eventDispatcher->reveal()
        );
    }

    public function testAuthorizeDoesNothing()
    {
        $stripePayment = new StripePayment();

        $this->paymentManager->authorize($stripePayment);

        $this->assertEquals(PaymentInterface::STATE_CART, $stripePayment->getState());
    }

    public function testAuthorizeCreatesCharge()
    {
        $stripePayment = new StripePayment();
        $stripePayment->setState(PaymentInterface::STATE_NEW);
        $stripePayment->setStripeToken('tok_123456');
        $stripePayment->setCurrencyCode('EUR');

        $order = $this->prophesize(OrderInterface::class);
        $order
            ->getTotal()
            ->willReturn(900);
        $order
            ->getNumber()
            ->willReturn('000001');

        $stripePayment->setOrder($order->reveal());

        $this->shouldSendStripeRequest('POST', '/v1/charges', [
            'amount' => 900,
            'currency' => 'eur',
            'source' => 'tok_123456',
            'description' => 'Order 000001',
            'capture' => 'false',
        ]);

        $this->eventDispatcher
            ->dispatch(PaymentAuthorizeEvent::NAME, Argument::type(PaymentAuthorizeEvent::class))
            ->shouldBeCalled();

        $this->paymentManager->authorize($stripePayment);

        $this->assertNotNull($stripePayment->getCharge());
        $this->assertEquals(PaymentInterface::STATE_AUTHORIZED, $stripePayment->getState());
    }

    public function testCaptureCapturesCharge()
    {
        $stripePayment = new StripePayment();
        $stripePayment->setState(PaymentInterface::STATE_AUTHORIZED);
        $stripePayment->setStripeToken('tok_123456');
        $stripePayment->setCurrencyCode('EUR');
        $stripePayment->setCharge('ch_123456');

        $this->shouldSendStripeRequest('GET', '/v1/charges/ch_123456');
        $this->shouldSendStripeRequest('POST', '/v1/charges/ch_123456/capture');

        $this->paymentManager->capture($stripePayment);

        $this->assertEquals(PaymentInterface::STATE_COMPLETED, $stripePayment->getState());
    }

    public function testCreateTransferWithNoRestaurant()
    {
        $order = $this->prophesize(OrderInterface::class);
        $order
            ->getRestaurant()
            ->willReturn(null);

        $stripePayment = new StripePayment();
        $stripePayment->setState(PaymentInterface::STATE_NEW);
        $stripePayment->setOrder($order->reveal());

        $this->shouldNotSendStripeRequest();

        $this->paymentManager->createTransfer($stripePayment);
    }

    public function testCreateTransferWithNoStripeAccount()
    {
        // $stripePayment = $this->prophesize(StripePayment::class);
        $order = $this->prophesize(OrderInterface::class);
        $restaurant = $this->prophesize(Restaurant::class);

        $restaurant
            ->getStripeAccount()
            ->willReturn(null);

        $order
            ->getRestaurant()
            ->willReturn($restaurant->reveal());

        $stripePayment = new StripePayment();
        $stripePayment->setState(PaymentInterface::STATE_NEW);
        $stripePayment->setOrder($order->reveal());

        $this->shouldNotSendStripeRequest();

        $this->paymentManager->createTransfer($stripePayment);
    }

    public function testCreateTransferCreatesTransfer()
    {
        $stripeAccount = $this->prophesize(StripeAccount::class);
        $order = $this->prophesize(OrderInterface::class);
        $restaurant = $this->prophesize(Restaurant::class);

        $stripeAccount
            ->getStripeUserId()
            ->willReturn('acct_123');

        $restaurant
            ->getStripeAccount()
            ->willReturn($stripeAccount->reveal());

        $order
            ->getRestaurant()
            ->willReturn($restaurant->reveal());
        $order
            ->getTotal()
            ->willReturn(10000);
        $order
            ->getFeeTotal()
            ->willReturn(500);

        $stripePayment = new StripePayment();
        $stripePayment->setCurrencyCode('EUR');
        $stripePayment->setCharge('ch_123456');
        $stripePayment->setOrder($order->reveal());

        $this->shouldSendStripeRequest('POST', '/v1/transfers', [
            'amount' => 9500,
            'currency' => 'eur',
            'destination' => 'acct_123',
            'source_transaction' => 'ch_123456',
        ]);

        $this->paymentManager->createTransfer($stripePayment);
    }
}
