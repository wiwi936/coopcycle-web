<?php

namespace AppBundle\Controller;

use AppBundle\Entity\Delivery;
use AppBundle\Entity\StripePayment;
use AppBundle\Form\StripePaymentType;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @Route("/{_locale}/pub", requirements={ "_locale": "%locale_regex%" })
 */
class PublicController extends Controller
{
    /**
     * @Route("/o/{number}", name="public_order")
     * @Template
     */
    public function orderAction($number, Request $request)
    {
        $stateMachineFactory = $this->get('sm.factory');
        $paymentManager = $this->get('coopcycle.payment_manager');

        $order = $this->get('sylius.repository.order')->findOneBy([
            'number' => $number
        ]);

        if (null === $order) {
            throw new NotFoundHttpException(sprintf('Order %s does not exist', $number));
        }

        $stripePayment = $order->getLastPayment();
        $stateMachine = $stateMachineFactory->get($stripePayment, PaymentTransitions::GRAPH);

        $parameters = [
            'order' => $order,
            'stripe_payment' => $stripePayment,
        ];

        if ($stateMachine->can(PaymentTransitions::TRANSITION_COMPLETE)) {

            $form = $this->createForm(StripePaymentType::class, $stripePayment);

            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {

                try {

                    $stripePayment->setStripeToken($form->get('stripeToken')->getData());
                    $paymentManager->charge($stripePayment);

                } catch (\Exception $e) {

                } finally {
                    $this->get('sylius.manager.order')->flush();
                }

                // TODO Manage failed payment

                if (PaymentInterface::STATE_FAILED === $stripePayment->getState()) {
                    return array_merge($parameters, [
                        'error' => $stripePayment->getLastError()
                    ]);
                }

                return $this->redirectToRoute('public_order', ['number' => $number]);
            }

            $parameters = array_merge($parameters, ['form' => $form->createView()]);
        }

        return $parameters;
    }

    /**
     * @Route("/i/{number}", name="public_invoice")
     * @Template
     */
    public function invoiceAction($number, Request $request)
    {
        $order = $this->get('sylius.repository.order')->findOneBy([
            'number' => $number
        ]);

        if (null === $order) {
            throw new NotFoundHttpException(sprintf('Order %s does not exist', $number));
        }

        $delivery = $this->getDoctrine()
            ->getRepository(Delivery::class)
            ->findOneByOrder($order);

        $html = $this->renderView('@App/Pdf/delivery.html.twig', [
            'order' => $order,
            'delivery' => $delivery,
            'customer' => $order->getCustomer()
        ]);

        return new Response($this->get('knp_snappy.pdf')->getOutputFromHtml($html), 200, [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
