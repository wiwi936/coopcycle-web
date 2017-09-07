<?php

namespace AppBundle\Tests\Controller;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class BaseControllerTestCase extends WebTestCase
{
    /*
     * A base function for controller tests.
     *
     * Provide the following :
     *  - a logIn method to act as a logged-in user
     *  - clean-up the database at the end of each test class
     */

    /* @var Client */
    public $client = null;

    /* @var EntityManager */
    protected $em = null;

    private $container = null;
    private $session = null;

    public function setUp () {

        parent::setUp();

        $this->client = static::createClient();
        $this->container = $this->client->getContainer();

        $this->em = $this->container->get('doctrine.orm.entity_manager');
        $this->generateSchema();

        $this->session = $this->container->get('session');
    }

    private function generateSchema()
    {
       $metadata = $this->em->getMetadataFactory()->getAllMetadata();

       if(!empty($metadata)) {
           $tool = new SchemaTool($this->em);
           $tool->dropSchema($metadata);
           $tool->createSchema($metadata);
       }

    }

    public function logIn() {
        /*
            Create a user and log it.

            Useful to test login-protected endpoints.
        */

        $userManager = $this->client->getContainer()->get('fos_user.user_manager');

        $user = $userManager->createUser();

        $user->setEmail('test_user@example.com');
        $user->setUsername('test_user');
        $user->setGivenName('test_user');
        $user->setFamilyName('test_user');
        $user->setTelephone('0677886868');
        $user->setPlainPassword('foo');
        $user->setEnabled(true);
        $user->addRole('ROLE_CUSTOMER');
        $userManager->updateUser($user);

        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->selectButton('_submit')->form(array(
            '_username'  => $user->getUsername(),
            '_password'  => 'foo',
        ));
        $this->client->submit($form);
    }

}
