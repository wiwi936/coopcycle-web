<?php

namespace AppBundle\Controller;

use AppBundle\Tests\Controller\BaseControllerTestCase;
use AppBundle\Entity\DeliveryAddress;
use AppBundle\Entity\Menu\MenuItem;
use AppBundle\Entity\Restaurant;
use AppBundle\Utils\Cart;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Loader;

class OrderControllerTest extends BaseControllerTestCase
{

    /* @var \AppBundle\Entity\Restaurant */
    private $restaurant;

    /* @var MenuItem */
    private $menuItem;

    /* @var */
    private $doctrine;

    public function setUp ()
    {
        parent::setUp();

        $this->doctrine = $this->client->getContainer()->get('doctrine');
        $loader = new Loader();
        $loader->loadFromDirectory('./tests/Fixtures');
        $executor = new ORMExecutor($this->client->getContainer()->get('doctrine.orm.entity_manager'));
        $executor->execute($loader->getFixtures(), true);

        $menuItemRepo = $this->doctrine->getRepository(MenuItem::class);
        $this->menuItem = $menuItemRepo->find(1);

        $restaurantRepo = $this->doctrine->getRepository(Restaurant::class);
        $this->restaurant = $restaurantRepo->find(1);
    }

    public function testCreateOrderWithSavedAddress()
    {
        $this->markTestSkipped();

        $this->logIn();

        $cart = new Cart($this->restaurant);
        $cart->addItem($this->menuItem);

        $deliveryAddressData = array(
            'streetAddress' => '55 rue des Pins',
            'postalCode' => '75000'
        );

        $data = array(
            'order[createDeliveryAddress]' => true,
            'order[deliveryAddress]' => $deliveryAddressData
        );

        $session = $this->client->getContainer()->get('session');
        $session->set('cart', $cart);
        $session->save();

        $crawler = $this->client->request('GET', '/order/');
        $rep = $this->client->getResponse();

        $form = $crawler->selectButton('Continue to payment')->form($data);

        $this->client->submit($form);
        $rep = $this->client->getResponse();

        // Check that we are redirected to payment page
        $this->assertEquals(301, $rep->getStatusCode());

        // Check that the delivery address is correctly created
        $deliveryAddressRepo = $this->doctrine->getRepository(DeliveryAddress::class);
    }

}
