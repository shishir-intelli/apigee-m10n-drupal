<?php

/*
 * Copyright 2021 Google Inc.
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

namespace Drupal\apigee_m10n\Entity;

use Apigee\Edge\Entity\EntityInterface as EdgeEntityInterface;
use Drupal\apigee_edge\Entity\FieldableEdgeEntityBase;
use Apigee\Edge\Api\Monetization\Entity\XRatePlan as MonetizationRatePlan;
use Apigee\Edge\Api\Monetization\Entity\DeveloperCategoryRatePlanInterface;
use Apigee\Edge\Api\Monetization\Entity\DeveloperInterface;
use Apigee\Edge\Api\Monetization\Entity\DeveloperRatePlanInterface;
use Apigee\Edge\Api\Monetization\Entity\XRatePlanRevisionInterface;
use Apigee\Edge\Api\Monetization\Entity\StandardXRatePlan;
use Drupal\apigee_m10n\Entity\Property\DescriptionPropertyAwareDecoratorTrait;
use Drupal\apigee_m10n\Entity\Property\BillingPeriodPropertyAwareDecoratorTrait;
use Drupal\apigee_m10n\Entity\Property\PaymentFundingModelPropertyAwareDecoratorTrait;
use Drupal\apigee_m10n\Entity\Property\CurrencyCodePropertyAwareDecoratorTrait;
use Drupal\apigee_m10n\Entity\Property\FixedFeeFrequencyPropertyAwareDecoratorTrait;
use Drupal\apigee_m10n\Entity\Property\ConsumptionPricingTypePropertyAwareDecoratorTrait;
use Drupal\apigee_m10n\Entity\Property\RevenueShareTypePropertyAwareDecoratorTrait;
use Drupal\apigee_m10n\Entity\Property\StartTimePropertyAwareDecoratorTrait;
use Drupal\apigee_m10n\Entity\Property\EndTimePropertyAwareDecoratorTrait;
use Drupal\apigee_m10n\Entity\Property\DisplayNamePropertyAwareDecoratorTrait;
use Drupal\apigee_m10n\Entity\Property\ApiXProductPropertyAwareDecoratorTrait;
use Drupal\apigee_m10n\Entity\Property\IdPropertyAwareDecoratorTrait;
use Drupal\apigee_m10n\Entity\Property\NamePropertyAwareDecoratorTrait;
use Drupal\apigee_m10n\Entity\Property\XPackagePropertyAwareDecoratorTrait;
use Apigee\Edge\Api\Monetization\Structure\RatePlanXFee;
use Apigee\Edge\Api\Monetization\Structure\FixedRecurringFee;
use Apigee\Edge\Api\Monetization\Structure\ConsumptionPricingRates;
use Apigee\Edge\Api\Monetization\Structure\RevenueShareRates;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\user\Entity\User;

/**
 * Defines the xrate plan entity class.
 *
 * @\Drupal\apigee_edge\Annotation\EdgeEntityType(
 *   id = "xrate_plan",
 *   label = @Translation("XRate plan"),
 *   label_singular = @Translation("XRate plan"),
 *   label_plural = @Translation("XRate plans"),
 *   label_count = @PluralTranslation(
 *     singular = "@count XRate plan",
 *     plural = "@count XRate plans",
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\apigee_m10n\Entity\Storage\XRatePlanStorage",
 *     "access" = "Drupal\apigee_m10n\Entity\Access\XRatePlanAccessControlHandler",
 *     "subscription_access" = "Drupal\apigee_m10n\Entity\Access\XRatePlanSubscriptionAccessHandler",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *   },
 *   links = {
 *     "canonical" = "/user/{user}/monetization/xproduct/{xproduct}/plan/{xrate_plan}",
 *     "purchase" = "/user/{user}/monetization/xproduct/{xproduct}/plan/{xrate_plan}/purchase",

 *   },
 *   entity_keys = {
 *     "id" = "id",
 *   },
 *   admin_permission = "administer apigee monetization",
 *   field_ui_base_route = "apigee_m10n.settings.rate_plan_x",
 * )
 */
class XRatePlan extends FieldableEdgeEntityBase implements XRatePlanInterface {

  use DescriptionPropertyAwareDecoratorTrait;
  use BillingPeriodPropertyAwareDecoratorTrait;
  use PaymentFundingModelPropertyAwareDecoratorTrait;
  use CurrencyCodePropertyAwareDecoratorTrait;
  use FixedFeeFrequencyPropertyAwareDecoratorTrait;
  use ConsumptionPricingTypePropertyAwareDecoratorTrait;
  use RevenueShareTypePropertyAwareDecoratorTrait;
  use StartTimePropertyAwareDecoratorTrait;
  use EndTimePropertyAwareDecoratorTrait;
  use IdPropertyAwareDecoratorTrait;
  use NamePropertyAwareDecoratorTrait;
  use XPackagePropertyAwareDecoratorTrait;
  use ApiXProductPropertyAwareDecoratorTrait;
  use DisplayNamePropertyAwareDecoratorTrait;

  public const ENTITY_TYPE_ID = 'xrate_plan';

  /**
   * The future xrate plan of this rate plan.
   *
   * If this is set to FALSE, we've already checked for a future plan and there
   * isn't one.
   *
   * @var \Drupal\apigee_m10n\Entity\XRatePlanInterface|false
   */
  protected $futureRatePlan;


  /**
   * The current rate plan if this is a future rate plan.
   *
   * If this is set to FALSE, this is not a future plan or we've already tried
   * to locate a previous revision that is currently available and failed.
   *
   * @var \Drupal\apigee_m10n\Entity\XRatePlanInterface|false
   */
  protected $currentRatePlan;

  /**
   * Constructs a `xrate_plan` entity.
   *
   * @param array $values
   *   An array of values to set, keyed by property name.
   * @param null|string $entity_type
   *   Type of the entity. It is optional because constructor sets its default
   *   value.
   * @param \Apigee\Edge\Entity\EntityInterface|null $decorated
   *   The SDK entity that this Drupal entity decorates.
   *
   * @throws \ReflectionException
   */
  public function __construct(array $values, ?string $entity_type = NULL, ?EdgeEntityInterface $decorated = NULL) {
    /** @var \Apigee\Edge\Api\Management\Entity\DeveloperAppInterface $decorated */
    $entity_type = $entity_type ?? static::ENTITY_TYPE_ID;
    parent::__construct($values, $entity_type, $decorated);
  }

  /**
   * {@inheritdoc}
   */
  protected static function decoratedClass(): string {
    return StandardXRatePlan::class;
  }

  /**
   * {@inheritdoc}
   */
  public static function idProperty(): string {
    return MonetizationRatePlan::idProperty();
  }

  /**
   * {@inheritdoc}
   */
  protected static function propertyToBaseFieldBlackList(): array {
    return array_merge(parent::propertyToBaseFieldBlackList(), ['package']);
  }

  /**
   * {@inheritdoc}
   */
  protected static function propertyToBaseFieldTypeMap(): array {
    return [
      'description' => 'string',
      'setUpFee' => 'apigee_price',
      'ratePlanXFee' => 'apigee_rate_plan_xfee',
      'fixedRecurringFee' => 'apigee_rate_plan_fixed_recurringfee',
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected static function getProperties(): array {
    return [
      'xproduct' => 'entity_reference',
      'products' => 'entity_reference',
    ] + parent::getProperties();
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    /** @var \Drupal\Core\Field\BaseFieldDefinition[] $definitions */
    $definitions = parent::baseFieldDefinitions($entity_type);
    //kint($definitions);exit;
    // The Setup fee.
    $definitions['ratePlanXFee']->setCardinality(-1)
      ->setLabel(t('SetupFee'))
      ->setDescription(t('Set up fee for Apigee X.'));

    // The fixedRecurring fee.
    $definitions['fixedRecurringFee']->setCardinality(-1)
      ->setLabel(t('FixedRecurringFee'))
      ->setDescription(t('Fixed recurring fee for Apigee X.'));

    // The API products are many-to-one.
    $definitions['apiProduct']->setCardinality(1)
      ->setSetting('target_type', 'apixProduct')
      ->setLabel(t('Product'))
      ->setDescription(t('The API product X the rate plan belongs to.'));

    // The API products are many-to-one.
    //$definitions['products']->setCardinality(-1)
     // ->setSetting('target_type', 'api_product')
     // ->setLabel(t('Products'))
     // ->setDescription(t('Products included in the product X.'));

    return $definitions;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   *   Thrown if the entity type doesn't exist.
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   *   Thrown if the storage handler couldn't be loaded.
   */
  public static function loadRatePlansByXProduct(string $product_bundle): array {
    return \Drupal::entityTypeManager()
      ->getStorage(static::ENTITY_TYPE_ID)
      ->loadRatePlansByXProduct($product_bundle);
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   *   Thrown if the entity type doesn't exist.
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   *   Thrown if the storage handler couldn't be loaded.
   */
  public static function loadById(string $product_bundle_id, string $id): XRatePlanInterface {
    return \Drupal::entityTypeManager()
      ->getStorage(static::ENTITY_TYPE_ID)
      ->loadById($product_bundle_id, $id);
  }

  /**
   * {@inheritdoc}
   */
  protected function drupalEntityId(): ?string {
    return $this->decorated->id();
  }

  /**
   * {@inheritdoc}
   */
  public function toUrl($rel = 'canonical', array $options = []) {
    // The string formatter assumes entities are revisionable.
    $rel = ($rel === 'revision') ? 'canonical' : $rel;
    // Build the URL.
    $url = parent::toUrl($rel, $options);
    $url->setRouteParameter('user', $this->getUser()->id());
    $url->setRouteParameter('xproduct', $this->getXProductId());

    return $url;
  }

  /**
   * {@inheritdoc}
   */
  protected function getFieldValue(string $field_name) {
    // Add the price value to the field name for price items.
    $field_name = in_array($field_name, [
      'setUpFee',
    ]) ? "{$field_name}PriceValue" : $field_name;

    return parent::getFieldValue($field_name);
  }

  /**
   * {@inheritdoc}
   */
  public function getPurchase():? array {
    return [
      'user' => $this->getUser(),
    ];
  }

  /**
   * Get user from route parameter and fall back to current user if empty.
   *
   * @return \Drupal\Core\Session\AccountProxyInterface
   *   Returns user entity.
   */
  private function getUser() {
    // The route parameters still need to be set.
    $route_user = \Drupal::routeMatch()->getParameter('user');
    // Sometimes the param converter hasn't converted the user.
    if (is_string($route_user)) {
      $route_user = User::load($route_user);
    }
    return $route_user ?: \Drupal::currentUser();
  }

  /**
   * {@inheritdoc}
   */
  public function getFuturePlanStartDate(): ?\DateTimeImmutable {
    return NULL;
    return ($future_plan = $this->getFutureRatePlan()) ? $future_plan->getStartDate() : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getFuturePlanLinks() {
    return NULL;
    // Display the current plan link if this is a future rate plan.
    if ($this->isFutureRatePlan() && ($current = $this->getCurrentRatePlan())) {
      return [
        [
          'title' => t('Current rate plan'),
          'uri' => $current->toUrl()->toUriString(),
          'options' => ['attributes' => ['class' => ['rate-plan-current-link']]],
        ],
        [
          'title' => t('Future rate plan'),
          'uri' => $this->toUrl()->toUriString(),
          'options' => ['attributes' => ['class' => ['is-active', 'rate-plan-future-link']]],
        ],
      ];
    }
    // Display the future plan link if one exists.
    if ($future_plan = $this->getFutureRatePlan()) {
      return [
        [
          'title' => t('Current rate plan'),
          'uri' => $this->toUrl()->toUriString(),
          'options' => ['attributes' => ['class' => ['is-active', 'rate-plan-current-link']]],
        ],
        [
          'title' => t('Future rate plan'),
          'uri' => $future_plan->toUrl()->toUriString(),
          'options' => ['attributes' => ['class' => ['rate-plan-future-link']]],
        ],
      ];
    }

    return NULL;
  }

  /**
   * Gets whether or not this is a future plan.
   *
   * @return bool
   *   Whether or not this is a future plan.
   */
  protected function isFutureRatePlan(): bool {
    return NULL;
    $start_date = $this->getStartDate();
    $today = new \DateTime('today', $start_date->getTimezone());
    // This is a future rate plan if it is a revision and the start date is in
    // the future.
    return $this->decorated() instanceof XRatePlanRevisionInterface && $today < $start_date;
  }

  /**
   * Gets the future plan for this rate plan if one exists.
   *
   * @return \Drupal\apigee_m10n\Entity\XRatePlanInterface|null
   *   The future rate plan or null.
   */
  protected function getFutureRatePlan(): ?XRatePlanInterface {
    return NULL;
    if (!isset($this->futureRatePlan)) {
      // Use the entity storage to load the future rate plan.
      $this->futureRatePlan = ($future_plan = \Drupal::entityTypeManager()->getStorage($this->entityTypeId)->loadFutureRatePlan($this)) ? $future_plan : FALSE;
    }

    return $this->futureRatePlan ?: NULL;
  }

  /**
   * Gets the current plan if this is a future plan.
   *
   * @return \Drupal\apigee_m10n\Entity\XRatePlanInterface|null
   *   The current rate plan or null.
   */
  protected function getCurrentRatePlan(): ?XRatePlanInterface {
    return NULL;
    if (!isset($this->currentRatePlan)) {
      // Check whether or not this plan has a parent plan.
      if (($decorated = $this->decorated()) && $decorated instanceof XRatePlanRevisionInterface) {
        // Create a date to compare whether a plan is current. Current means the
        // plan has already started and is a parent revision of this plan.
        $today = new \DateTimeImmutable('today', $this->getStartDate()->getTimezone());
        // The previous revision is our starting point.
        $parent_plan = $decorated->getPreviousRatePlanRevision();
        // Loop through parents until the current plan is found.
        while ($parent_plan && ($today < $parent_plan->getStartDate() || $today > $parent_plan->getEndDate())) {
          // Get the next parent if it exists.
          $parent_plan = $parent_plan instanceof XRatePlanRevisionInterface ? $parent_plan->getPreviousRatePlanRevision() : NULL;
        }
        // If the $parent_plan is currently available, it is our current plan.
        $this->currentRatePlan = ($parent_plan->getStartDate() < $today && $parent_plan->getEndDate() > $today)
          ? XRatePlan::createFrom($parent_plan)
          : FALSE;
      }
      else {
        $this->currentRatePlan = FALSE;
      }
    }

    return $this->currentRatePlan ?: NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return Cache::mergeTags(parent::getCacheContexts(), ['url.developer']);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    return Cache::mergeTags(parent::getCacheTags(), ($entity = $this->get('xproduct')->entity) ? $entity->getCacheTags() : []);
  }

  /**
   * {@inheritdoc}
   */
  public function getFrequencyDuration(): ?int {
    return $this->decorated->getFrequencyDuration();
  }

  /**
   * {@inheritdoc}
   */
  public function setFrequencyDuration(int $frequencyDuration): void {
    $this->decorated->setFrequencyDuration($frequencyDuration);
  }

  /**
   * {@inheritdoc}
   */
  public function getFrequencyDurationType(): ?string {
    return $this->decorated->getFrequencyDurationType();
  }

  /**
   * {@inheritdoc}
   */
  public function setFrequencyDurationType(string $frequencyDurationType): void {
    $this->decorated->setFrequencyDurationType($frequencyDurationType);
  }

  /**
   * {@inheritdoc}
   */
  public function getRatePlanXFee(): array {
    return $this->decorated->getRatePlanXFee();
  }

  /**
   * {@inheritdoc}
   */
  public function setRatePlanXFee(RatePlanXFee ...$ratePlanXFee): void {
    $this->decorated->setRatePlanXFee(...$ratePlanXFee);
  }

  /**
   * {@inheritdoc}
   */
  public function getFixedRecurringFee(): array {
    return $this->decorated->getFixedRecurringFee();
  }

  /**
   * {@inheritdoc}
   */
  public function setFixedRecurringFee(FixedRecurringFee ...$fixedRecurringFee): void {
    $this->decorated->setFixedRecurringFee(...$fixedRecurringFee);
  }

  /**
   * {@inheritdoc}
   */
  public function getConsumptionPricingRates(): array {
    return $this->decorated->getConsumptionPricingRates();
  }

  /**
   * {@inheritdoc}
   */
  public function setConsumptionPricingRates(ConsumptionPricingRates ...$consumptionPricingRates): void {
    $this->decorated->setConsumptionPricingRates(...$consumptionPricingRates);
  }
    
  /**
   * {@inheritdoc}
   */
  public function getRevenueShareRates(): array {
    return $this->decorated->getRevenueShareRates();
  }

  /**
   * {@inheritdoc}
   */
  public function setRevenueShareRates(RevenueShareRates ...$revenueShareRates): void {
    $this->decorated->setRevenueShareRates(...$revenueShareRates);
  }
  
  /**
   * {@inheritdoc}
   */
  public function isPublished(): bool {
    return $this->decorated->isPublished();
  }

  /**
   * {@inheritdoc}
   */
  public function setPublished(bool $published): void {
    $this->decorated->setPublished($published);
  }

  /**
   * {@inheritdoc}
   */
  public function getProductBundle() {
    return ['target_id' => $this->getXProductId()];
  }

  /**
   * {@inheritdoc}
   */
  public function getXProductId() {
    return $this->decorated()->getApiProduct() ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getType(): string {
    if ($this->decorated instanceof DeveloperRatePlanInterface) {
      return XRatePlanInterface::TYPE_DEVELOPER;
    }
    elseif ($this->decorated instanceof DeveloperCategoryRatePlanInterface) {
      return XRatePlanInterface::TYPE_DEVELOPER_CATEGORY;
    }

    return XRatePlanInterface::TYPE_STANDARD;
  }

  /**
   * {@inheritdoc}
   */
  public function getDeveloper(): ?DeveloperInterface {
    if ($this->decorated instanceof DeveloperRatePlanInterface) {
      return $this->decorated->getDeveloper();
    }

    return NULL;
  }

}
