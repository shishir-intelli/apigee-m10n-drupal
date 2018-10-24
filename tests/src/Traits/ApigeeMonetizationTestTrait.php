<?php

/**
 * Copyright 2018 Google Inc.
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License version 2 as published by the
 * Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
 * or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public
 * License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc., 51
 * Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 */

namespace Drupal\Tests\apigee_m10n\Traits;

use Apigee\Edge\Api\Monetization\Controller\OrganizationProfileController;
use Apigee\Edge\Api\Monetization\Controller\SupportedCurrencyController;
use Apigee\Edge\Api\Monetization\Entity\ApiPackage;
use Apigee\Edge\Api\Monetization\Entity\ApiProduct as MonetizationApiProduct;
use Apigee\Edge\Api\Monetization\Entity\Property\FreemiumPropertiesInterface;
use Apigee\Edge\Api\Monetization\Structure\RatePlanDetail;
use Apigee\Edge\Api\Monetization\Structure\RatePlanRateRateCard;
use Drupal\apigee_edge\Entity\ApiProduct;
use Drupal\apigee_m10n\Entity\RatePlan;
use Drupal\apigee_m10n\Entity\RatePlanInterface;
use Drupal\apigee_m10n\EnvironmentVariable;
use Drupal\Component\Serialization\Json;
use Drupal\key\Entity\Key;
use Drupal\Tests\apigee_edge\Functional\ApigeeEdgeTestTrait;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use GuzzleHttp\Psr7\Response;

/**
 * Setup helpers for monetization tests.
 */
trait ApigeeMonetizationTestTrait {

  use ApigeeEdgeTestTrait {
    setUp as edgeSetup;
    createAccount as edgeCreateAccount;
  }

  /**
   * The mock handler stack is responsible for serving queued api responses.
   *
   * @var \Drupal\apigee_mock_client\MockHandlerStack
   */
  protected $stack;

  /**
   * The SDK Connector client.
   *
   * This will have it's http client stack replaced a mock stack.
   *
   * @var \Drupal\apigee_edge\SDKConnectorInterface
   */
  protected $sdk_connector;

  /**
   * The SDK controller factory.
   *
   * @var \Drupal\apigee_m10n\ApigeeSdkControllerFactoryInterface
   */
  protected $controller_factory;

  /**
   * The clean up queue.
   *
   * @var array
   *   An associative array with a `callback` and a `weight` key. Some items will
   *   need to be called before others which is the reason for the weight system.
   */
  protected $cleanup_queue;

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function setUp() {
    $this->stack              = $this->container->get('apigee_mock_client.mock_http_handler_stack');
    $this->sdk_connector      = $this->container->get('apigee_edge.sdk_connector');

    $this->initAuth();
    // `::initAuth` has to happen before getting the controller factory.
    $this->controller_factory = $this->container->get('apigee_m10n.sdk_controller_factory');
  }

  /**
   * Initialize SDK connector.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function initAuth() {

    // Create new Apigee Edge basic auth key.
    $key = Key::create([
      'id'           => 'apigee_m10n_test_auth',
      'label'        => 'Apigee M10n Test Authorization',
      'key_type'     => 'apigee_edge_basic_auth',
      'key_provider' => 'config',
      'key_input'    => 'apigee_edge_basic_auth_input',
    ]);
    $key->setKeyValue(Json::encode([
      'endpoint'     => getenv(EnvironmentVariable::$APIGEE_EDGE_ENDPOINT),
      'organization' => getenv(EnvironmentVariable::$APIGEE_EDGE_ORGANIZATION),
      'username'     => getenv(EnvironmentVariable::$APIGEE_EDGE_USERNAME),
      'password'     => getenv(EnvironmentVariable::$APIGEE_EDGE_PASSWORD),
    ]));
    $key->save();

    $this->config('apigee_edge.auth')
      ->set('active_key', 'apigee_m10n_test_auth')
      ->set('active_key_oauth_token', '')
      ->save();
  }

  /**
   * Create an account.
   *
   * We override this function from `ApigeeEdgeTestTrait` so we can queue the
   * appropriate response upon account creation.
   *
   * {@inheritdoc}
   *
   * @throws \Exception
   */
  protected function createAccount(array $permissions = [], bool $status = TRUE, string $prefix = ''): ?UserInterface {
    $rid = NULL;
    if ($permissions) {
      $rid = $this->createRole($permissions);
      $this->assertTrue($rid, 'Role created');
    }

    $edit = [
      'first_name' => $this->randomMachineName(),
      'last_name' => $this->randomMachineName(),
      'name' => $this->randomMachineName(),
      'pass' => user_password(),
      'status' => $status,
    ];
    if ($rid) {
      $edit['roles'][] = $rid;
    }
    if ($prefix) {
      $edit['mail'] = "{$prefix}.{$edit['name']}@example.com";
    }
    else {
      $edit['mail'] = "{$edit['name']}@example.com";
    }

    $account = User::create($edit);

    // Queue up a created response.
    $this->queueDeveloperResponse($account, 201);

    // Save the user.
    $account->save();

    $this->assertTrue($account->id(), 'User created.');
    if (!$account->id()) {
      return NULL;
    }

    // This is here to make drupalLogin() work.
    $account->passRaw = $edit['pass'];

    $this->cleanup_queue[] = [
      'weight' => 99,
      'callback' => function () use ($account) {
        // Prepare for deleting the developer.
        $this->queueDeveloperResponse($account);
        $this->queueDeveloperResponse($account);
        // Delete it.
        $account->delete();
      }
    ];

    return $account;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Exception
   */
  protected function createProduct(): MonetizationApiProduct {
    /** @var \Drupal\apigee_edge\Entity\ApiProduct $product */
    $product = ApiProduct::create([
      'id'            => strtolower($this->randomMachineName()),
      'name'          => $this->randomMachineName(),
      'description'   => $this->getRandomGenerator()->sentences(3),
      'displayName'   => $this->getRandomGenerator()->word(16),
      'approvalType'  => ApiProduct::APPROVAL_TYPE_AUTO,
    ]);
    // Need to queue the management spi product.
    $this->stack->queueMockResponse(['api_product' => ['product' => $product]]);
    $product->save();

    $this->stack->queueMockResponse(['api_product_mint' => ['product' => $product]]);
    $controller = $this->controller_factory->apiProductController();

    // Remove the product in the cleanup queue.
    $this->cleanup_queue[] = [
      'weight' => 20,
      'callback' => function () use ($product) {
        $this->stack->queueMockResponse(['api_product' => ['product' => $product]]);
        $product->delete();
      }
    ];

    return $controller->load($product->getName());
  }

  /**
   * Create an API package.
   *
   * @throws \Exception
   */
  protected function createPackage(): ApiPackage {
    $products = [];
    for ($i=rand(1,4); $i > 0; $i--) {
      $products[] = $this->createProduct();
    }

    $package = new ApiPackage([
      'name'        => $this->randomMachineName(),
      'description' => $this->getRandomGenerator()->sentences(3),
      'displayName' => $this->getRandomGenerator()->word(16),
      'apiProducts' => $products,
      'status'      => 'CREATED', //CREATED, ACTIVE, INACTIVE
    ]);
    // Get a package controller from the package controller factory.
    $package_controller = $this->controller_factory->apiPackageController();
    $this->stack
      ->queueMockResponse(['get_monetization_package' => ['package' => $package]]);
    $package_controller->create($package);

    // Remove the packages in the cleanup queue.
    $this->cleanup_queue[] = [
      'weight' => 10,
      'callback' => function () use ($package, $package_controller) {
        $this->stack->queueMockResponse('no_content');
        $package_controller->delete($package->id());
      }
    ];

    return $package;
  }

  /**
   * Create a package rate plan for a given package.
   *
   * @throws \Exception
   */
  protected function createPackageRatePlan(ApiPackage $package): RatePlanInterface {
    $client = $this->sdk_connector->getClient();
    $org_name = $this->sdk_connector->getOrganization();

    // Load the org profile.
    $org_controller = new OrganizationProfileController($org_name, $client);
    $this->stack->queueMockResponse('get_organization_profile');
    $org = $org_controller->load();

    // The usd currency should be available by default.
    $currency_controller = new SupportedCurrencyController($org_name, $this->sdk_connector->getClient());
    $this->stack->queueMockResponse('get_supported_currency');
    $currency = $currency_controller->load('usd');

    /** @var RatePlanInterface $rate_plan */
    $rate_plan = RatePlan::create([
      'advance'               => TRUE,
      'customPaymentTerm'     => TRUE,
      'description'           => $this->getRandomGenerator()->sentences(3),
      'displayName'           => $this->getRandomGenerator()->word(16),
      'earlyTerminationFee'   => '2.0000',
      'endDate'               => new \DateTimeImmutable('now + 1 year'),
      'frequencyDuration'     => 1,
      'frequencyDurationType' => FreemiumPropertiesInterface::FREEMIUM_DURATION_MONTH,
      'freemiumUnit'          => 1,
      'id'                    => strtolower($this->randomMachineName()),
      'isPrivate'             => 'false',
      //'monetizationPackage'   => {},
      'name'                  => $this->randomMachineName(),
      'paymentDueDays'        => '30',
      'prorate'               => FALSE,
      'published'             => TRUE,
      'ratePlanDetails'       => [
        new RatePlanDetail([
          "aggregateFreemiumCounters" => TRUE,
          "aggregateStandardCounters" => TRUE,
          "aggregateTransactions"     => TRUE,
          'currency'                  => $currency,
          "customPaymentTerm"         => TRUE,
          "duration"                  => 1,
          "durationType"              => "MONTH",
          "freemiumDuration"          => 1,
          "freemiumDurationType"      => "MONTH",
          "freemiumUnit"              => 110,
          "id"                        => strtolower($this->randomMachineName(16)),
          "meteringType"              => "UNIT",
          'org'                       => $org,
          "paymentDueDays"            => "30",
          'ratePlanRates'             => [],
          "ratingParameter"           => "VOLUME",
          "type"                      => "RATECARD",
        ])
       ],
      'recurringFee'          => '3.0000',
      'recurringStartUnit'    => '1',
      'recurringType'         => 'CALENDAR',
      'setUpFee'              => '1.0000',
      'startDate'             => new \DateTimeImmutable('2018-07-26 00:00:00'),
      'type'                  => 'STANDARD',
    ]);
    $rate_plan->setOrganization($org);
    $rate_plan->setCurrency($currency);
    $rate_plan->setPackage($package);

    $this->stack
      ->queueMockResponse(['rate_plan' => ['plan' => $rate_plan]]);
    $rate_plan->save();

    /**
     * The rateplan rates are being added after the entity is saved  because of
     * the following error.
     *
     * @todo: The following error must be fixed before we can save ratePlan rates.
     *
     * @code
     * TypeError : Return value of Apigee\Edge\Api\Monetization\Structure\RatePlanRate::getStartUnit() must be of the type integer, null returned
     * .../vendor/apigee/apigee-client-php/src/Api/Monetization/Structure/RatePlanRate.php:41
     * @code
     */
    $rate_plan->getRatePlanDetails()[0]->setRatePlanRates([
      'id'        => strtolower($this->randomMachineName()),
      'rate'      => rand(5,20),
    ]);

    // Remove the rate plan in the cleanup queue.
    $this->cleanup_queue[] = [
      'weight' => 9,
      'callback' => function () use ($rate_plan) {
        $this->stack->queueMockResponse('no_content');
        $rate_plan->delete();
      }
    ];

    return $rate_plan;
  }

  /**
   * Queues up a mock developer response.
   *
   * @param \Drupal\user\UserInterface $developer
   *   The developer user to get properties from.
   * @param string|null $response_code
   *   Add a response code to override the default.
   *
   * @throws \Exception
   */
  protected function queueDeveloperResponse(UserInterface $developer, $response_code = NULL) {
    $context = empty($response_code) ? [] : ['status_code' => $response_code];

    $context['developer'] = $developer;
    $context['org_name'] = $this->sdk_connector->getOrganization();

    $this->stack->queueMockResponse(['get_developer' => $context]);
  }

  /**
   * Helper function to queue up an org response since every test will need it,.
   *
   * @param bool $monetized
   *   Whether or not the org is monetized.
   *
   * @throws \Twig_Error_Loader
   * @throws \Twig_Error_Runtime
   * @throws \Twig_Error_Syntax
   */
  protected function queueOrg($monetized = TRUE) {
    $this->stack
      ->queueMockResponse(['get_organization' => ['monetization_enabled' => $monetized ? 'true' : 'false']]);
  }

  /**
   * Helper for testing element text by css selector.
   *
   * @param string $selector
   *   The css selector.
   * @param string $text
   *   The test to look for.
   *
   * @throws \Behat\Mink\Exception\ElementTextException
   */
  protected function assertCssElementContains($selector, $text) {
    $this->assertSession()->elementTextContains('css', $selector, $text);
  }

  /**
   * Helper for testing the lack of element text by css selector.
   *
   * @param string $selector
   *   The css selector.
   * @param string $text
   *   The test to look for.
   *
   * @throws \Behat\Mink\Exception\ElementTextException
   */
  protected function assertCssElementNotContains($selector, $text) {
    $this->assertSession()->elementTextNotContains('css', $selector, $text);
  }

  /**
   * Makes sure no HTTP Client exceptions have been logged.
   */
  public function assertNoClientError() {
    $exceptions = $this->sdk_connector->getClient()->getJournal()->getLastException();
    static::assertEmpty(
      $exceptions,
      'A HTTP error has been logged in the Journal.'
    );
  }

  /**
   * Performs cleanup tasks after each individual test method has been run.
   */
  protected function tearDown() {
    if (!empty($this->cleanup_queue)) {
      $errors = [];
      // Sort all callbacks by weight. Lower weights will be executed first.
      usort($this->cleanup_queue, function ($a, $b) {
        return ($a['weight'] === $b['weight']) ? 0 : (($a['weight'] < $b['weight']) ? -1 : 1);
      });
      // Loop through the queue and execute callbacks.
      foreach ($this->cleanup_queue as $claim) {
        try {
          $claim['callback']();
        } catch (\Exception $ex) {
          $errors[] = $ex;
        }
      }

      parent::tearDown();

      if (!empty($errors)) {
        throw new \Exception('Errors found while processing the cleanup queue', 0, reset($errors));
      }
    }
  }

}
