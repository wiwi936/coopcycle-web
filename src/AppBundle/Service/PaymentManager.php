<?php

namespace AppBundle\Service;

use AppBundle\Entity\StripePayment;
use AppBundle\Entity\StripeTransfer;
use AppBundle\Event\PaymentAuthorizeEvent;
use AppBundle\Sylius\StripeTransfer\StripeTransferTransitions;
use SM\Factory\FactoryInterface as StateMachineFactoryInterface;
use Stripe;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class PaymentManager
{
    private $stateMachineFactory;
    private $settingsManager;
    private $eventDispatcher;

    public function __construct(
        StateMachineFactoryInterface $stateMachineFactory,
        SettingsManager $settingsManager,
        EventDispatcherInterface $eventDispatcher)
    {
        $this->stateMachineFactory = $stateMachineFactory;
        $this->settingsManager = $settingsManager;
        $this->eventDispatcher = $eventDispatcher;
    }

    public function charge(StripePayment $stripePayment)
    {
        $stripeToken = $stripePayment->getStripeToken();

        if (null === $stripeToken) {
            // TODO Throw Exception
            return;
        }

        Stripe\Stripe::setApiKey($this->settingsManager->get('stripe_secret_key'));
        $stateMachine = $this->stateMachineFactory->get($stripePayment, PaymentTransitions::GRAPH);

        try {

            $order = $stripePayment->getOrder();

            $charge = Stripe\Charge::create(array(
                'amount' => $order->getTotal(),
                'currency' => strtolower($stripePayment->getCurrencyCode()),
                'source' => $stripeToken,
                'description' => sprintf('Order %s', $order->getNumber()),
                'capture' => true,
            ));

            $stripePayment->setCharge($charge->id);

            $stateMachine->apply(PaymentTransitions::TRANSITION_COMPLETE);

        } catch (\Exception $e) {
            $stripePayment->setLastError($e->getMessage());
            $stateMachine->apply(PaymentTransitions::TRANSITION_FAIL);
        }
    }

    public function authorize(StripePayment $stripePayment)
    {
        $stripeToken = $stripePayment->getStripeToken();

        if (null === $stripeToken) {
            // TODO Throw Exception
            return;
        }

        Stripe\Stripe::setApiKey($this->settingsManager->get('stripe_secret_key'));
        $stateMachine = $this->stateMachineFactory->get($stripePayment, PaymentTransitions::GRAPH);

        try {

            $order = $stripePayment->getOrder();

            $charge = Stripe\Charge::create(array(
                'amount' => $order->getTotal(),
                'currency' => strtolower($stripePayment->getCurrencyCode()),
                'source' => $stripeToken,
                'description' => sprintf('Order %s', $order->getNumber()),
                // To authorize a payment without capturing it,
                // make a charge request that also includes the capture parameter with a value of false.
                // This instructs Stripe to only authorize the amount on the customer’s card.
                'capture' => false,
            ));

            $stripePayment->setCharge($charge->id);

            $stateMachine->apply('authorize');

            $this->dispatchEvent($stripePayment, PaymentAuthorizeEvent::NAME);

        } catch (\Exception $e) {
            $stripePayment->setLastError($e->getMessage());
            $stateMachine->apply(PaymentTransitions::TRANSITION_FAIL);
        }
    }

    public function capture(StripePayment $stripePayment)
    {
        // TODO Check payment state is STATE_AUTHORIZED

        Stripe\Stripe::setApiKey($this->settingsManager->get('stripe_secret_key'));

        $stateMachine = $this->stateMachineFactory->get($stripePayment, PaymentTransitions::GRAPH);

        try {

            $charge = Stripe\Charge::retrieve($stripePayment->getCharge());

            if ($charge->captured) {
                throw new \Exception('Charge already captured');
            }

            $charge->capture();

            $stateMachine->apply(PaymentTransitions::TRANSITION_COMPLETE);

        } catch (\Exception $e) {
            $stripePayment->setLastError($e->getMessage());
            $stateMachine->apply(PaymentTransitions::TRANSITION_FAIL);
        }
    }

    public function complete(PaymentInterface $payment)
    {
        $stateMachine = $this->stateMachineFactory->get($payment, PaymentTransitions::GRAPH);
        $stateMachine->apply(PaymentTransitions::TRANSITION_COMPLETE);

        // $this->createTransfer($payment);
    }

    public function createTransfer(PaymentInterface $stripePayment)
    {
        $order = $stripePayment->getOrder();
        $restaurant = $order->getRestaurant();

        // There is no restaurant
        if (null === $restaurant) {
            return;
        }

        $stripeAccount = $restaurant->getStripeAccount();

        // There is no Stripe account
        if (null === $stripeAccount) {
            return;
        }

        $amount = $order->getTotal() - $order->getFeeTotal();

        $stripeTransfer = StripeTransfer::create($stripePayment, $amount);

        $transferStateMachine = $this->stateMachineFactory->get($stripeTransfer, StripeTransferTransitions::GRAPH);

        // transfer the correct amount to restaurant owner/shop
        // ref https://stripe.com/docs/connect/charges-transfers
        try {

            Stripe\Stripe::setApiKey($this->settingsManager->get('stripe_secret_key'));

            $transfer = Stripe\Transfer::create([
                'amount' => $amount,
                'currency' => strtolower($stripePayment->getCurrencyCode()),
                'destination' => $stripeAccount->getStripeUserId(),
                // ref https://stripe.com/docs/connect/charges-transfers#transfer-availability
                'source_transaction' => $stripePayment->getCharge()
            ]);

            $stripeTransfer->setTransfer($transfer->id);

            $transferStateMachine->apply(StripeTransferTransitions::TRANSITION_COMPLETE);
        } catch (\Exception $e) {
            $stripeTransfer->setLastError($e->getMessage());
            $transferStateMachine->apply(StripeTransferTransitions::TRANSITION_FAIL);
        }
    }

    public function dispatchEvent(PaymentInterface $payment, $eventName)
    {
        switch ($eventName) {
            case PaymentAuthorizeEvent::NAME:
                $this->eventDispatcher->dispatch(PaymentAuthorizeEvent::NAME, new PaymentAuthorizeEvent($payment));
                break;
        }
    }
}
