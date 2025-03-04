<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Form\Tests\Extension\Validator\ViolationMapper;

use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\DataMapper\DataMapper;
use Symfony\Component\Form\Extension\Validator\ViolationMapper\ViolationMapper;
use Symfony\Component\Form\FileUploadError;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormConfigBuilder;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\Tests\Extension\Validator\ViolationMapper\Fixtures\Issue;
use Symfony\Component\Form\Tests\Fixtures\DummyFormRendererEngine;
use Symfony\Component\Form\Tests\Fixtures\FixedTranslator;
use Symfony\Component\PropertyAccess\PropertyPath;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class ViolationMapperTest extends TestCase
{
    private const LEVEL_0 = 0;
    private const LEVEL_1 = 1;
    private const LEVEL_1B = 2;
    private const LEVEL_2 = 3;

    private EventDispatcher $dispatcher;
    private ViolationMapper $mapper;
    private string $message;
    private string $messageTemplate;
    private array $params;

    protected function setUp(): void
    {
        $this->dispatcher = new EventDispatcher();
        $this->mapper = new ViolationMapper();
        $this->message = 'Message';
        $this->messageTemplate = 'Message template';
        $this->params = ['foo' => 'bar'];
    }

    protected function getForm($name = 'name', $propertyPath = null, $dataClass = null, $errorMapping = [], $inheritData = false, $synchronized = true, array $options = [])
    {
        $config = new FormConfigBuilder($name, $dataClass, $this->dispatcher, [
            'error_mapping' => $errorMapping,
        ] + $options);
        $config->setMapped($options['mapped'] ?? true);
        $config->setInheritData($inheritData);
        $config->setPropertyPath($propertyPath);
        $config->setCompound(true);
        $config->setDataMapper(new DataMapper());
        $config->setErrorBubbling($options['error_bubbling'] ?? false);

        if (!$synchronized) {
            $config->addViewTransformer(new CallbackTransformer(
                fn ($normData) => $normData,
                function () { throw new TransformationFailedException(); }
            ));
        }

        return new Form($config);
    }

    protected function getConstraintViolation($propertyPath, $root = null): ConstraintViolation
    {
        return new ConstraintViolation($this->message, $this->messageTemplate, $this->params, $root, $propertyPath, null);
    }

    protected function getFormError(ConstraintViolationInterface $violation, FormInterface $form): FormError
    {
        $error = new FormError($this->message, $this->messageTemplate, $this->params, null, $violation);
        $error->setOrigin($form);

        return $error;
    }

    public function testMappingErrorsWhenFormIsNotMapped()
    {
        $form = $this->getForm('name', null, Issue::class, [
            'child1' => 'child2',
        ]);

        $violation = $this->getConstraintViolation('children[child1].data', $form);

        $child1Form = $this->getForm('child1', null, null, [], false, true, [
            'mapped' => false,
        ]);
        $child2Form = $this->getForm('child2', null, null, [], false, true, [
            'mapped' => false,
        ]);

        $form->add($child1Form);
        $form->add($child2Form);

        $form->submit([]);

        $this->mapper->mapViolation($violation, $form);

        $this->assertCount(0, $form->getErrors());
        $this->assertCount(0, $form->get('child1')->getErrors());
        $this->assertCount(1, $form->get('child2')->getErrors());
    }

    public function testMapToFormInheritingParentDataIfDataDoesNotMatch()
    {
        $violation = $this->getConstraintViolation('children[address].data.foo');
        $parent = $this->getForm('parent');
        $child = $this->getForm('address', 'address', null, [], true);
        $grandChild = $this->getForm('street');

        $parent->add($child);
        $child->add($grandChild);

        $parent->submit([]);

        $this->mapper->mapViolation($violation, $parent);

        $this->assertCount(0, $parent->getErrors(), $parent->getName().' should not have an error, but has one');
        $this->assertEquals([$this->getFormError($violation, $child)], iterator_to_array($child->getErrors()), $child->getName().' should have an error, but has none');
        $this->assertCount(0, $grandChild->getErrors(), $grandChild->getName().' should not have an error, but has one');
    }

    public function testFollowDotRules()
    {
        $violation = $this->getConstraintViolation('data.foo');
        $parent = $this->getForm('parent', null, null, [
            'foo' => 'address',
        ]);
        $child = $this->getForm('address', null, null, [
            '.' => 'street',
        ]);
        $grandChild = $this->getForm('street', null, null, [
            '.' => 'name',
        ]);
        $grandGrandChild = $this->getForm('name');

        $parent->add($child);
        $child->add($grandChild);
        $grandChild->add($grandGrandChild);

        $parent->submit([]);

        $this->mapper->mapViolation($violation, $parent);

        $this->assertCount(0, $parent->getErrors(), $parent->getName().' should not have an error, but has one');
        $this->assertCount(0, $child->getErrors(), $child->getName().' should not have an error, but has one');
        $this->assertCount(0, $grandChild->getErrors(), $grandChild->getName().' should not have an error, but has one');
        $this->assertEquals([$this->getFormError($violation, $grandGrandChild)], iterator_to_array($grandGrandChild->getErrors()), $grandGrandChild->getName().' should have an error, but has none');
    }

    public function testAbortMappingIfNotSynchronized()
    {
        $violation = $this->getConstraintViolation('children[address].data.street');
        $parent = $this->getForm('parent');
        $child = $this->getForm('address', 'address', null, [], false, false);
        // even though "street" is synchronized, it should not have any errors
        // due to its parent not being synchronized
        $grandChild = $this->getForm('street', 'street');

        $parent->add($child);
        $child->add($grandChild);

        // invoke the transformer and mark the form unsynchronized
        $parent->submit([]);

        $this->mapper->mapViolation($violation, $parent);

        $this->assertCount(0, $parent->getErrors(), $parent->getName().' should not have an error, but has one');
        $this->assertCount(0, $child->getErrors(), $child->getName().' should not have an error, but has one');
        $this->assertCount(0, $grandChild->getErrors(), $grandChild->getName().' should not have an error, but has one');
    }

    public function testAbortDotRuleMappingIfNotSynchronized()
    {
        $violation = $this->getConstraintViolation('data.address');
        $parent = $this->getForm('parent');
        $child = $this->getForm('address', 'address', null, [
            '.' => 'street',
        ], false, false);
        // even though "street" is synchronized, it should not have any errors
        // due to its parent not being synchronized
        $grandChild = $this->getForm('street');

        $parent->add($child);
        $child->add($grandChild);

        // invoke the transformer and mark the form unsynchronized
        $parent->submit([]);

        $this->mapper->mapViolation($violation, $parent);

        $this->assertCount(0, $parent->getErrors(), $parent->getName().' should not have an error, but has one');
        $this->assertCount(0, $child->getErrors(), $child->getName().' should not have an error, but has one');
        $this->assertCount(0, $grandChild->getErrors(), $grandChild->getName().' should not have an error, but has one');
    }

    public function testMappingIfNotSubmitted()
    {
        $violation = $this->getConstraintViolation('children[address].data.street');
        $parent = $this->getForm('parent');
        $child = $this->getForm('address', 'address');
        $grandChild = $this->getForm('street', 'street');

        $parent->add($child);
        $child->add($grandChild);

        // Disable automatic submission of missing fields
        $parent->submit([], false);
        $child->submit([], false);

        // $grandChild is not submitted

        $this->mapper->mapViolation($violation, $parent);

        $this->assertCount(0, $parent->getErrors(), $parent->getName().' should not have an error, but has one');
        $this->assertCount(0, $child->getErrors(), $child->getName().' should not have an error, but has one');
        $this->assertCount(1, $grandChild->getErrors(), $grandChild->getName().' should have one error');
    }

    public function testDotRuleMappingIfNotSubmitted()
    {
        $violation = $this->getConstraintViolation('data.address');
        $parent = $this->getForm('parent');
        $child = $this->getForm('address', 'address', null, [
            '.' => 'street',
        ]);
        $grandChild = $this->getForm('street');

        $parent->add($child);
        $child->add($grandChild);

        // Disable automatic submission of missing fields
        $parent->submit([], false);
        $child->submit([], false);

        // $grandChild is not submitted

        $this->mapper->mapViolation($violation, $parent);

        $this->assertCount(0, $parent->getErrors(), $parent->getName().' should not have an error, but has one');
        $this->assertCount(0, $child->getErrors(), $child->getName().' should not have an error, but has one');
        $this->assertCount(1, $grandChild->getErrors(), $grandChild->getName().' should have one error');
    }

    public static function provideDefaultTests()
    {
        // The mapping must be deterministic! If a child has the property path "[street]",
        // "data[street]" should be mapped, but "data.street" should not!
        return [
            // mapping target, child name, its property path, grand child name, its property path, violation path
            [self::LEVEL_0, 'address', 'address', 'street', 'street', ''],
            [self::LEVEL_0, 'address', 'address', 'street', 'street', 'data'],

            [self::LEVEL_2, 'address', 'address', 'street', 'street', 'children[address].children[street].data'],
            [self::LEVEL_2, 'address', 'address', 'street', 'street', 'children[address].children[street].data.prop'],
            [self::LEVEL_1, 'address', 'address', 'street', 'street', 'children[address].data'],
            [self::LEVEL_2, 'address', 'address', 'street', 'street', 'children[address].data.street'],
            [self::LEVEL_2, 'address', 'address', 'street', 'street', 'children[address].data.street.prop'],
            [self::LEVEL_1, 'address', 'address', 'street', 'street', 'children[address].data[street]'],
            [self::LEVEL_1, 'address', 'address', 'street', 'street', 'children[address].data[street].prop'],
            [self::LEVEL_2, 'address', 'address', 'street', 'street', 'data.address.street'],
            [self::LEVEL_2, 'address', 'address', 'street', 'street', 'data.address.street.prop'],
            [self::LEVEL_1, 'address', 'address', 'street', 'street', 'data.address[street]'],
            [self::LEVEL_1, 'address', 'address', 'street', 'street', 'data.address[street].prop'],
            [self::LEVEL_0, 'address', 'address', 'street', 'street', 'data[address].street'],
            [self::LEVEL_0, 'address', 'address', 'street', 'street', 'data[address].street.prop'],
            [self::LEVEL_0, 'address', 'address', 'street', 'street', 'data[address][street]'],
            [self::LEVEL_0, 'address', 'address', 'street', 'street', 'data[address][street].prop'],

            [self::LEVEL_2, 'address', 'address', 'street', '[street]', 'children[address].children[street].data'],
            [self::LEVEL_2, 'address', 'address', 'street', '[street]', 'children[address].children[street].data.prop'],
            [self::LEVEL_1, 'address', 'address', 'street', '[street]', 'children[address].data'],
            [self::LEVEL_1, 'address', 'address', 'street', '[street]', 'children[address].data.street'],
            [self::LEVEL_1, 'address', 'address', 'street', '[street]', 'children[address].data.street.prop'],
            [self::LEVEL_2, 'address', 'address', 'street', '[street]', 'children[address].data[street]'],
            [self::LEVEL_2, 'address', 'address', 'street', '[street]', 'children[address].data[street].prop'],
            [self::LEVEL_1, 'address', 'address', 'street', '[street]', 'data.address.street'],
            [self::LEVEL_1, 'address', 'address', 'street', '[street]', 'data.address.street.prop'],
            [self::LEVEL_2, 'address', 'address', 'street', '[street]', 'data.address[street]'],
            [self::LEVEL_2, 'address', 'address', 'street', '[street]', 'data.address[street].prop'],
            [self::LEVEL_0, 'address', 'address', 'street', '[street]', 'data[address].street'],
            [self::LEVEL_0, 'address', 'address', 'street', '[street]', 'data[address].street.prop'],
            [self::LEVEL_0, 'address', 'address', 'street', '[street]', 'data[address][street]'],
            [self::LEVEL_0, 'address', 'address', 'street', '[street]', 'data[address][street].prop'],

            [self::LEVEL_2, 'address', '[address]', 'street', 'street', 'children[address].children[street].data'],
            [self::LEVEL_2, 'address', '[address]', 'street', 'street', 'children[address].children[street].data.prop'],
            [self::LEVEL_1, 'address', '[address]', 'street', 'street', 'children[address].data'],
            [self::LEVEL_2, 'address', '[address]', 'street', 'street', 'children[address].data.street'],
            [self::LEVEL_2, 'address', '[address]', 'street', 'street', 'children[address].data.street.prop'],
            [self::LEVEL_1, 'address', '[address]', 'street', 'street', 'children[address].data[street]'],
            [self::LEVEL_1, 'address', '[address]', 'street', 'street', 'children[address].data[street].prop'],
            [self::LEVEL_0, 'address', '[address]', 'street', 'street', 'data.address.street'],
            [self::LEVEL_0, 'address', '[address]', 'street', 'street', 'data.address.street.prop'],
            [self::LEVEL_0, 'address', '[address]', 'street', 'street', 'data.address[street]'],
            [self::LEVEL_0, 'address', '[address]', 'street', 'street', 'data.address[street].prop'],
            [self::LEVEL_2, 'address', '[address]', 'street', 'street', 'data[address].street'],
            [self::LEVEL_2, 'address', '[address]', 'street', 'street', 'data[address].street.prop'],
            [self::LEVEL_1, 'address', '[address]', 'street', 'street', 'data[address][street]'],
            [self::LEVEL_1, 'address', '[address]', 'street', 'street', 'data[address][street].prop'],

            [self::LEVEL_2, 'address', '[address]', 'street', '[street]', 'children[address].children[street].data'],
            [self::LEVEL_2, 'address', '[address]', 'street', '[street]', 'children[address].children[street].data.prop'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[street]', 'children[address].data'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[street]', 'children[address].data.street'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[street]', 'children[address].data.street.prop'],
            [self::LEVEL_2, 'address', '[address]', 'street', '[street]', 'children[address].data[street]'],
            [self::LEVEL_2, 'address', '[address]', 'street', '[street]', 'children[address].data[street].prop'],
            [self::LEVEL_0, 'address', '[address]', 'street', '[street]', 'data.address.street'],
            [self::LEVEL_0, 'address', '[address]', 'street', '[street]', 'data.address.street.prop'],
            [self::LEVEL_0, 'address', '[address]', 'street', '[street]', 'data.address[street]'],
            [self::LEVEL_0, 'address', '[address]', 'street', '[street]', 'data.address[street].prop'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[street]', 'data[address].street'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[street]', 'data[address].street.prop'],
            [self::LEVEL_2, 'address', '[address]', 'street', '[street]', 'data[address][street]'],
            [self::LEVEL_2, 'address', '[address]', 'street', '[street]', 'data[address][street].prop'],

            [self::LEVEL_2, 'address', 'person.address', 'street', 'street', 'children[address].children[street].data'],
            [self::LEVEL_2, 'address', 'person.address', 'street', 'street', 'children[address].children[street].data.prop'],
            [self::LEVEL_1, 'address', 'person.address', 'street', 'street', 'children[address].data'],
            [self::LEVEL_2, 'address', 'person.address', 'street', 'street', 'children[address].data.street'],
            [self::LEVEL_2, 'address', 'person.address', 'street', 'street', 'children[address].data.street.prop'],
            [self::LEVEL_1, 'address', 'person.address', 'street', 'street', 'children[address].data[street]'],
            [self::LEVEL_1, 'address', 'person.address', 'street', 'street', 'children[address].data[street].prop'],
            [self::LEVEL_2, 'address', 'person.address', 'street', 'street', 'data.person.address.street'],
            [self::LEVEL_2, 'address', 'person.address', 'street', 'street', 'data.person.address.street.prop'],
            [self::LEVEL_1, 'address', 'person.address', 'street', 'street', 'data.person.address[street]'],
            [self::LEVEL_1, 'address', 'person.address', 'street', 'street', 'data.person.address[street].prop'],
            [self::LEVEL_0, 'address', 'person.address', 'street', 'street', 'data.person[address].street'],
            [self::LEVEL_0, 'address', 'person.address', 'street', 'street', 'data.person[address].street.prop'],
            [self::LEVEL_0, 'address', 'person.address', 'street', 'street', 'data.person[address][street]'],
            [self::LEVEL_0, 'address', 'person.address', 'street', 'street', 'data.person[address][street].prop'],
            [self::LEVEL_0, 'address', 'person.address', 'street', 'street', 'data[person].address.street'],
            [self::LEVEL_0, 'address', 'person.address', 'street', 'street', 'data[person].address.street.prop'],
            [self::LEVEL_0, 'address', 'person.address', 'street', 'street', 'data[person].address[street]'],
            [self::LEVEL_0, 'address', 'person.address', 'street', 'street', 'data[person].address[street].prop'],
            [self::LEVEL_0, 'address', 'person.address', 'street', 'street', 'data[person][address].street'],
            [self::LEVEL_0, 'address', 'person.address', 'street', 'street', 'data[person][address].street.prop'],
            [self::LEVEL_0, 'address', 'person.address', 'street', 'street', 'data[person][address][street]'],
            [self::LEVEL_0, 'address', 'person.address', 'street', 'street', 'data[person][address][street].prop'],

            [self::LEVEL_2, 'address', 'person.address', 'street', '[street]', 'children[address].children[street].data'],
            [self::LEVEL_2, 'address', 'person.address', 'street', '[street]', 'children[address].children[street].data.prop'],
            [self::LEVEL_1, 'address', 'person.address', 'street', '[street]', 'children[address].data'],
            [self::LEVEL_1, 'address', 'person.address', 'street', '[street]', 'children[address].data.street'],
            [self::LEVEL_1, 'address', 'person.address', 'street', '[street]', 'children[address].data.street.prop'],
            [self::LEVEL_2, 'address', 'person.address', 'street', '[street]', 'children[address].data[street]'],
            [self::LEVEL_2, 'address', 'person.address', 'street', '[street]', 'children[address].data[street].prop'],
            [self::LEVEL_1, 'address', 'person.address', 'street', '[street]', 'data.person.address.street'],
            [self::LEVEL_1, 'address', 'person.address', 'street', '[street]', 'data.person.address.street.prop'],
            [self::LEVEL_2, 'address', 'person.address', 'street', '[street]', 'data.person.address[street]'],
            [self::LEVEL_2, 'address', 'person.address', 'street', '[street]', 'data.person.address[street].prop'],
            [self::LEVEL_0, 'address', 'person.address', 'street', '[street]', 'data.person[address].street'],
            [self::LEVEL_0, 'address', 'person.address', 'street', '[street]', 'data.person[address].street.prop'],
            [self::LEVEL_0, 'address', 'person.address', 'street', '[street]', 'data.person[address][street]'],
            [self::LEVEL_0, 'address', 'person.address', 'street', '[street]', 'data.person[address][street].prop'],
            [self::LEVEL_0, 'address', 'person.address', 'street', '[street]', 'data[person].address.street'],
            [self::LEVEL_0, 'address', 'person.address', 'street', '[street]', 'data[person].address.street.prop'],
            [self::LEVEL_0, 'address', 'person.address', 'street', '[street]', 'data[person].address[street]'],
            [self::LEVEL_0, 'address', 'person.address', 'street', '[street]', 'data[person].address[street].prop'],
            [self::LEVEL_0, 'address', 'person.address', 'street', '[street]', 'data[person][address].street'],
            [self::LEVEL_0, 'address', 'person.address', 'street', '[street]', 'data[person][address].street.prop'],
            [self::LEVEL_0, 'address', 'person.address', 'street', '[street]', 'data[person][address][street]'],
            [self::LEVEL_0, 'address', 'person.address', 'street', '[street]', 'data[person][address][street].prop'],

            [self::LEVEL_2, 'address', 'person[address]', 'street', 'street', 'children[address].children[street].data'],
            [self::LEVEL_2, 'address', 'person[address]', 'street', 'street', 'children[address].children[street].data.prop'],
            [self::LEVEL_1, 'address', 'person[address]', 'street', 'street', 'children[address].data'],
            [self::LEVEL_2, 'address', 'person[address]', 'street', 'street', 'children[address].data.street'],
            [self::LEVEL_2, 'address', 'person[address]', 'street', 'street', 'children[address].data.street.prop'],
            [self::LEVEL_1, 'address', 'person[address]', 'street', 'street', 'children[address].data[street]'],
            [self::LEVEL_1, 'address', 'person[address]', 'street', 'street', 'children[address].data[street].prop'],
            [self::LEVEL_0, 'address', 'person[address]', 'street', 'street', 'data.person.address.street'],
            [self::LEVEL_0, 'address', 'person[address]', 'street', 'street', 'data.person.address.street.prop'],
            [self::LEVEL_0, 'address', 'person[address]', 'street', 'street', 'data.person.address[street]'],
            [self::LEVEL_0, 'address', 'person[address]', 'street', 'street', 'data.person.address[street].prop'],
            [self::LEVEL_2, 'address', 'person[address]', 'street', 'street', 'data.person[address].street'],
            [self::LEVEL_2, 'address', 'person[address]', 'street', 'street', 'data.person[address].street.prop'],
            [self::LEVEL_1, 'address', 'person[address]', 'street', 'street', 'data.person[address][street]'],
            [self::LEVEL_1, 'address', 'person[address]', 'street', 'street', 'data.person[address][street].prop'],
            [self::LEVEL_0, 'address', 'person[address]', 'street', 'street', 'data[person].address.street'],
            [self::LEVEL_0, 'address', 'person[address]', 'street', 'street', 'data[person].address.street.prop'],
            [self::LEVEL_0, 'address', 'person[address]', 'street', 'street', 'data[person].address[street]'],
            [self::LEVEL_0, 'address', 'person[address]', 'street', 'street', 'data[person].address[street].prop'],
            [self::LEVEL_0, 'address', 'person[address]', 'street', 'street', 'data[person][address].street'],
            [self::LEVEL_0, 'address', 'person[address]', 'street', 'street', 'data[person][address].street.prop'],
            [self::LEVEL_0, 'address', 'person[address]', 'street', 'street', 'data[person][address][street]'],
            [self::LEVEL_0, 'address', 'person[address]', 'street', 'street', 'data[person][address][street].prop'],

            [self::LEVEL_2, 'address', 'person[address]', 'street', '[street]', 'children[address].children[street].data'],
            [self::LEVEL_2, 'address', 'person[address]', 'street', '[street]', 'children[address].children[street].data.prop'],
            [self::LEVEL_1, 'address', 'person[address]', 'street', '[street]', 'children[address].data'],
            [self::LEVEL_1, 'address', 'person[address]', 'street', '[street]', 'children[address].data.street'],
            [self::LEVEL_1, 'address', 'person[address]', 'street', '[street]', 'children[address].data.street.prop'],
            [self::LEVEL_2, 'address', 'person[address]', 'street', '[street]', 'children[address].data[street]'],
            [self::LEVEL_2, 'address', 'person[address]', 'street', '[street]', 'children[address].data[street].prop'],
            [self::LEVEL_0, 'address', 'person[address]', 'street', '[street]', 'data.person.address.street'],
            [self::LEVEL_0, 'address', 'person[address]', 'street', '[street]', 'data.person.address.street.prop'],
            [self::LEVEL_0, 'address', 'person[address]', 'street', '[street]', 'data.person.address[street]'],
            [self::LEVEL_0, 'address', 'person[address]', 'street', '[street]', 'data.person.address[street].prop'],
            [self::LEVEL_1, 'address', 'person[address]', 'street', '[street]', 'data.person[address].street'],
            [self::LEVEL_1, 'address', 'person[address]', 'street', '[street]', 'data.person[address].street.prop'],
            [self::LEVEL_2, 'address', 'person[address]', 'street', '[street]', 'data.person[address][street]'],
            [self::LEVEL_2, 'address', 'person[address]', 'street', '[street]', 'data.person[address][street].prop'],
            [self::LEVEL_0, 'address', 'person[address]', 'street', '[street]', 'data[person].address.street'],
            [self::LEVEL_0, 'address', 'person[address]', 'street', '[street]', 'data[person].address.street.prop'],
            [self::LEVEL_0, 'address', 'person[address]', 'street', '[street]', 'data[person].address[street]'],
            [self::LEVEL_0, 'address', 'person[address]', 'street', '[street]', 'data[person].address[street].prop'],
            [self::LEVEL_0, 'address', 'person[address]', 'street', '[street]', 'data[person][address].street'],
            [self::LEVEL_0, 'address', 'person[address]', 'street', '[street]', 'data[person][address].street.prop'],
            [self::LEVEL_0, 'address', 'person[address]', 'street', '[street]', 'data[person][address][street]'],
            [self::LEVEL_0, 'address', 'person[address]', 'street', '[street]', 'data[person][address][street].prop'],

            [self::LEVEL_2, 'address', '[person].address', 'street', 'street', 'children[address].children[street].data'],
            [self::LEVEL_2, 'address', '[person].address', 'street', 'street', 'children[address].children[street].data.prop'],
            [self::LEVEL_1, 'address', '[person].address', 'street', 'street', 'children[address].data'],
            [self::LEVEL_2, 'address', '[person].address', 'street', 'street', 'children[address].data.street'],
            [self::LEVEL_2, 'address', '[person].address', 'street', 'street', 'children[address].data.street.prop'],
            [self::LEVEL_1, 'address', '[person].address', 'street', 'street', 'children[address].data[street]'],
            [self::LEVEL_1, 'address', '[person].address', 'street', 'street', 'children[address].data[street].prop'],
            [self::LEVEL_0, 'address', '[person].address', 'street', 'street', 'data.person.address.street'],
            [self::LEVEL_0, 'address', '[person].address', 'street', 'street', 'data.person.address.street.prop'],
            [self::LEVEL_0, 'address', '[person].address', 'street', 'street', 'data.person.address[street]'],
            [self::LEVEL_0, 'address', '[person].address', 'street', 'street', 'data.person.address[street].prop'],
            [self::LEVEL_0, 'address', '[person].address', 'street', 'street', 'data.person[address].street'],
            [self::LEVEL_0, 'address', '[person].address', 'street', 'street', 'data.person[address].street.prop'],
            [self::LEVEL_0, 'address', '[person].address', 'street', 'street', 'data.person[address][street]'],
            [self::LEVEL_0, 'address', '[person].address', 'street', 'street', 'data.person[address][street].prop'],
            [self::LEVEL_2, 'address', '[person].address', 'street', 'street', 'data[person].address.street'],
            [self::LEVEL_2, 'address', '[person].address', 'street', 'street', 'data[person].address.street.prop'],
            [self::LEVEL_1, 'address', '[person].address', 'street', 'street', 'data[person].address[street]'],
            [self::LEVEL_1, 'address', '[person].address', 'street', 'street', 'data[person].address[street].prop'],
            [self::LEVEL_0, 'address', '[person].address', 'street', 'street', 'data[person][address].street'],
            [self::LEVEL_0, 'address', '[person].address', 'street', 'street', 'data[person][address].street.prop'],
            [self::LEVEL_0, 'address', '[person].address', 'street', 'street', 'data[person][address][street]'],
            [self::LEVEL_0, 'address', '[person].address', 'street', 'street', 'data[person][address][street].prop'],

            [self::LEVEL_2, 'address', '[person].address', 'street', '[street]', 'children[address].children[street].data'],
            [self::LEVEL_2, 'address', '[person].address', 'street', '[street]', 'children[address].children[street].data.prop'],
            [self::LEVEL_1, 'address', '[person].address', 'street', '[street]', 'children[address].data'],
            [self::LEVEL_1, 'address', '[person].address', 'street', '[street]', 'children[address].data.street'],
            [self::LEVEL_1, 'address', '[person].address', 'street', '[street]', 'children[address].data.street.prop'],
            [self::LEVEL_2, 'address', '[person].address', 'street', '[street]', 'children[address].data[street]'],
            [self::LEVEL_2, 'address', '[person].address', 'street', '[street]', 'children[address].data[street].prop'],
            [self::LEVEL_0, 'address', '[person].address', 'street', '[street]', 'data.person.address.street'],
            [self::LEVEL_0, 'address', '[person].address', 'street', '[street]', 'data.person.address.street.prop'],
            [self::LEVEL_0, 'address', '[person].address', 'street', '[street]', 'data.person.address[street]'],
            [self::LEVEL_0, 'address', '[person].address', 'street', '[street]', 'data.person.address[street].prop'],
            [self::LEVEL_0, 'address', '[person].address', 'street', '[street]', 'data.person[address].street'],
            [self::LEVEL_0, 'address', '[person].address', 'street', '[street]', 'data.person[address].street.prop'],
            [self::LEVEL_0, 'address', '[person].address', 'street', '[street]', 'data.person[address][street]'],
            [self::LEVEL_0, 'address', '[person].address', 'street', '[street]', 'data.person[address][street].prop'],
            [self::LEVEL_1, 'address', '[person].address', 'street', '[street]', 'data[person].address.street'],
            [self::LEVEL_1, 'address', '[person].address', 'street', '[street]', 'data[person].address.street.prop'],
            [self::LEVEL_2, 'address', '[person].address', 'street', '[street]', 'data[person].address[street]'],
            [self::LEVEL_2, 'address', '[person].address', 'street', '[street]', 'data[person].address[street].prop'],
            [self::LEVEL_0, 'address', '[person].address', 'street', '[street]', 'data[person][address].street'],
            [self::LEVEL_0, 'address', '[person].address', 'street', '[street]', 'data[person][address].street.prop'],
            [self::LEVEL_0, 'address', '[person].address', 'street', '[street]', 'data[person][address][street]'],
            [self::LEVEL_0, 'address', '[person].address', 'street', '[street]', 'data[person][address][street].prop'],

            [self::LEVEL_2, 'address', '[person][address]', 'street', 'street', 'children[address].children[street].data'],
            [self::LEVEL_2, 'address', '[person][address]', 'street', 'street', 'children[address].children[street].data.prop'],
            [self::LEVEL_1, 'address', '[person][address]', 'street', 'street', 'children[address]'],
            [self::LEVEL_1, 'address', '[person][address]', 'street', 'street', 'children[address].data'],
            [self::LEVEL_2, 'address', '[person][address]', 'street', 'street', 'children[address].data.street'],
            [self::LEVEL_2, 'address', '[person][address]', 'street', 'street', 'children[address].data.street.prop'],
            [self::LEVEL_1, 'address', '[person][address]', 'street', 'street', 'children[address].data[street]'],
            [self::LEVEL_1, 'address', '[person][address]', 'street', 'street', 'children[address].data[street].prop'],
            [self::LEVEL_0, 'address', '[person][address]', 'street', 'street', 'data.person.address.street'],
            [self::LEVEL_0, 'address', '[person][address]', 'street', 'street', 'data.person.address.street.prop'],
            [self::LEVEL_0, 'address', '[person][address]', 'street', 'street', 'data.person.address[street]'],
            [self::LEVEL_0, 'address', '[person][address]', 'street', 'street', 'data.person.address[street].prop'],
            [self::LEVEL_0, 'address', '[person][address]', 'street', 'street', 'data.person[address].street'],
            [self::LEVEL_0, 'address', '[person][address]', 'street', 'street', 'data.person[address].street.prop'],
            [self::LEVEL_0, 'address', '[person][address]', 'street', 'street', 'data.person[address][street]'],
            [self::LEVEL_0, 'address', '[person][address]', 'street', 'street', 'data.person[address][street].prop'],
            [self::LEVEL_0, 'address', '[person][address]', 'street', 'street', 'data[person].address.street'],
            [self::LEVEL_0, 'address', '[person][address]', 'street', 'street', 'data[person].address.street.prop'],
            [self::LEVEL_0, 'address', '[person][address]', 'street', 'street', 'data[person].address[street]'],
            [self::LEVEL_0, 'address', '[person][address]', 'street', 'street', 'data[person].address[street].prop'],
            [self::LEVEL_2, 'address', '[person][address]', 'street', 'street', 'data[person][address].street'],
            [self::LEVEL_2, 'address', '[person][address]', 'street', 'street', 'data[person][address].street.prop'],
            [self::LEVEL_1, 'address', '[person][address]', 'street', 'street', 'data[person][address][street]'],
            [self::LEVEL_1, 'address', '[person][address]', 'street', 'street', 'data[person][address][street].prop'],

            [self::LEVEL_2, 'address', '[person][address]', 'street', '[street]', 'children[address].children[street].data'],
            [self::LEVEL_2, 'address', '[person][address]', 'street', '[street]', 'children[address].children[street].data.prop'],
            [self::LEVEL_1, 'address', '[person][address]', 'street', '[street]', 'children[address].data'],
            [self::LEVEL_1, 'address', '[person][address]', 'street', '[street]', 'children[address].data.street'],
            [self::LEVEL_1, 'address', '[person][address]', 'street', '[street]', 'children[address].data.street.prop'],
            [self::LEVEL_2, 'address', '[person][address]', 'street', '[street]', 'children[address].data[street]'],
            [self::LEVEL_2, 'address', '[person][address]', 'street', '[street]', 'children[address].data[street].prop'],
            [self::LEVEL_0, 'address', '[person][address]', 'street', '[street]', 'data.person.address.street'],
            [self::LEVEL_0, 'address', '[person][address]', 'street', '[street]', 'data.person.address.street.prop'],
            [self::LEVEL_0, 'address', '[person][address]', 'street', '[street]', 'data.person.address[street]'],
            [self::LEVEL_0, 'address', '[person][address]', 'street', '[street]', 'data.person.address[street].prop'],
            [self::LEVEL_0, 'address', '[person][address]', 'street', '[street]', 'data.person[address].street'],
            [self::LEVEL_0, 'address', '[person][address]', 'street', '[street]', 'data.person[address].street.prop'],
            [self::LEVEL_0, 'address', '[person][address]', 'street', '[street]', 'data.person[address][street]'],
            [self::LEVEL_0, 'address', '[person][address]', 'street', '[street]', 'data.person[address][street].prop'],
            [self::LEVEL_0, 'address', '[person][address]', 'street', '[street]', 'data[person].address.street'],
            [self::LEVEL_0, 'address', '[person][address]', 'street', '[street]', 'data[person].address.street.prop'],
            [self::LEVEL_0, 'address', '[person][address]', 'street', '[street]', 'data[person].address[street]'],
            [self::LEVEL_0, 'address', '[person][address]', 'street', '[street]', 'data[person].address[street].prop'],
            [self::LEVEL_1, 'address', '[person][address]', 'street', '[street]', 'data[person][address].street'],
            [self::LEVEL_1, 'address', '[person][address]', 'street', '[street]', 'data[person][address].street.prop'],
            [self::LEVEL_2, 'address', '[person][address]', 'street', '[street]', 'data[person][address][street]'],
            [self::LEVEL_2, 'address', '[person][address]', 'street', '[street]', 'data[person][address][street].prop'],

            [self::LEVEL_2, 'address', 'address', 'street', 'office.street', 'children[address].children[street].data'],
            [self::LEVEL_2, 'address', 'address', 'street', 'office.street', 'children[address].children[street].data.prop'],
            [self::LEVEL_1, 'address', 'address', 'street', 'office.street', 'children[address].data'],
            [self::LEVEL_1, 'address', 'address', 'street', 'office.street', 'children[address].data.office'],
            [self::LEVEL_2, 'address', 'address', 'street', 'office.street', 'children[address].data.office.street'],
            [self::LEVEL_2, 'address', 'address', 'street', 'office.street', 'children[address].data.office.street.prop'],
            [self::LEVEL_1, 'address', 'address', 'street', 'office.street', 'children[address].data.office[street]'],
            [self::LEVEL_1, 'address', 'address', 'street', 'office.street', 'children[address].data.office[street].prop'],
            [self::LEVEL_1, 'address', 'address', 'street', 'office.street', 'children[address].data[office]'],
            [self::LEVEL_1, 'address', 'address', 'street', 'office.street', 'children[address].data[office].street'],
            [self::LEVEL_1, 'address', 'address', 'street', 'office.street', 'children[address].data[office].street.prop'],
            [self::LEVEL_1, 'address', 'address', 'street', 'office.street', 'children[address].data[office][street]'],
            [self::LEVEL_1, 'address', 'address', 'street', 'office.street', 'children[address].data[office][street].prop'],
            [self::LEVEL_2, 'address', 'address', 'street', 'office.street', 'data.address.office.street'],
            [self::LEVEL_2, 'address', 'address', 'street', 'office.street', 'data.address.office.street.prop'],
            [self::LEVEL_1, 'address', 'address', 'street', 'office.street', 'data.address.office[street]'],
            [self::LEVEL_1, 'address', 'address', 'street', 'office.street', 'data.address.office[street].prop'],
            [self::LEVEL_1, 'address', 'address', 'street', 'office.street', 'data.address[office].street'],
            [self::LEVEL_1, 'address', 'address', 'street', 'office.street', 'data.address[office].street.prop'],
            [self::LEVEL_1, 'address', 'address', 'street', 'office.street', 'data.address[office][street]'],
            [self::LEVEL_1, 'address', 'address', 'street', 'office.street', 'data.address[office][street].prop'],
            [self::LEVEL_0, 'address', 'address', 'street', 'office.street', 'data[address].office.street'],
            [self::LEVEL_0, 'address', 'address', 'street', 'office.street', 'data[address].office.street.prop'],
            [self::LEVEL_0, 'address', 'address', 'street', 'office.street', 'data[address].office[street]'],
            [self::LEVEL_0, 'address', 'address', 'street', 'office.street', 'data[address].office[street].prop'],
            [self::LEVEL_0, 'address', 'address', 'street', 'office.street', 'data[address][office].street'],
            [self::LEVEL_0, 'address', 'address', 'street', 'office.street', 'data[address][office].street.prop'],
            [self::LEVEL_0, 'address', 'address', 'street', 'office.street', 'data[address][office][street]'],
            [self::LEVEL_0, 'address', 'address', 'street', 'office.street', 'data[address][office][street].prop'],

            [self::LEVEL_2, 'address', '[address]', 'street', 'office.street', 'children[address].children[street].data'],
            [self::LEVEL_2, 'address', '[address]', 'street', 'office.street', 'children[address].children[street].data.prop'],
            [self::LEVEL_1, 'address', '[address]', 'street', 'office.street', 'children[address].data'],
            [self::LEVEL_1, 'address', '[address]', 'street', 'office.street', 'children[address].data.office'],
            [self::LEVEL_2, 'address', '[address]', 'street', 'office.street', 'children[address].data.office.street'],
            [self::LEVEL_2, 'address', '[address]', 'street', 'office.street', 'children[address].data.office.street.prop'],
            [self::LEVEL_1, 'address', '[address]', 'street', 'office.street', 'children[address].data.office[street]'],
            [self::LEVEL_1, 'address', '[address]', 'street', 'office.street', 'children[address].data.office[street].prop'],
            [self::LEVEL_1, 'address', '[address]', 'street', 'office.street', 'children[address].data[office]'],
            [self::LEVEL_1, 'address', '[address]', 'street', 'office.street', 'children[address].data[office].street'],
            [self::LEVEL_1, 'address', '[address]', 'street', 'office.street', 'children[address].data[office].street.prop'],
            [self::LEVEL_1, 'address', '[address]', 'street', 'office.street', 'children[address].data[office][street]'],
            [self::LEVEL_1, 'address', '[address]', 'street', 'office.street', 'children[address].data[office][street].prop'],
            [self::LEVEL_0, 'address', '[address]', 'street', 'office.street', 'data.address.office.street'],
            [self::LEVEL_0, 'address', '[address]', 'street', 'office.street', 'data.address.office.street.prop'],
            [self::LEVEL_0, 'address', '[address]', 'street', 'office.street', 'data.address.office[street]'],
            [self::LEVEL_0, 'address', '[address]', 'street', 'office.street', 'data.address.office[street].prop'],
            [self::LEVEL_0, 'address', '[address]', 'street', 'office.street', 'data.address[office].street'],
            [self::LEVEL_0, 'address', '[address]', 'street', 'office.street', 'data.address[office].street.prop'],
            [self::LEVEL_0, 'address', '[address]', 'street', 'office.street', 'data.address[office][street]'],
            [self::LEVEL_0, 'address', '[address]', 'street', 'office.street', 'data.address[office][street].prop'],
            [self::LEVEL_2, 'address', '[address]', 'street', 'office.street', 'data[address].office.street'],
            [self::LEVEL_2, 'address', '[address]', 'street', 'office.street', 'data[address].office.street.prop'],
            [self::LEVEL_1, 'address', '[address]', 'street', 'office.street', 'data[address].office[street]'],
            [self::LEVEL_1, 'address', '[address]', 'street', 'office.street', 'data[address].office[street].prop'],
            [self::LEVEL_1, 'address', '[address]', 'street', 'office.street', 'data[address][office].street'],
            [self::LEVEL_1, 'address', '[address]', 'street', 'office.street', 'data[address][office].street.prop'],
            [self::LEVEL_1, 'address', '[address]', 'street', 'office.street', 'data[address][office][street]'],
            [self::LEVEL_1, 'address', '[address]', 'street', 'office.street', 'data[address][office][street].prop'],

            [self::LEVEL_2, 'address', 'address', 'street', 'office[street]', 'children[address].children[street].data'],
            [self::LEVEL_2, 'address', 'address', 'street', 'office[street]', 'children[address].children[street].data.prop'],
            [self::LEVEL_1, 'address', 'address', 'street', 'office[street]', 'children[address].data'],
            [self::LEVEL_1, 'address', 'address', 'street', 'office[street]', 'children[address].data.office'],
            [self::LEVEL_1, 'address', 'address', 'street', 'office[street]', 'children[address].data.office.street'],
            [self::LEVEL_1, 'address', 'address', 'street', 'office[street]', 'children[address].data.office.street.prop'],
            [self::LEVEL_2, 'address', 'address', 'street', 'office[street]', 'children[address].data.office[street]'],
            [self::LEVEL_2, 'address', 'address', 'street', 'office[street]', 'children[address].data.office[street].prop'],
            [self::LEVEL_1, 'address', 'address', 'street', 'office[street]', 'children[address].data[office]'],
            [self::LEVEL_1, 'address', 'address', 'street', 'office[street]', 'children[address].data[office].street'],
            [self::LEVEL_1, 'address', 'address', 'street', 'office[street]', 'children[address].data[office].street.prop'],
            [self::LEVEL_1, 'address', 'address', 'street', 'office[street]', 'children[address].data[office][street]'],
            [self::LEVEL_1, 'address', 'address', 'street', 'office[street]', 'children[address].data[office][street].prop'],
            [self::LEVEL_1, 'address', 'address', 'street', 'office[street]', 'data.address.office.street'],
            [self::LEVEL_1, 'address', 'address', 'street', 'office[street]', 'data.address.office.street.prop'],
            [self::LEVEL_2, 'address', 'address', 'street', 'office[street]', 'data.address.office[street]'],
            [self::LEVEL_2, 'address', 'address', 'street', 'office[street]', 'data.address.office[street].prop'],
            [self::LEVEL_1, 'address', 'address', 'street', 'office[street]', 'data.address[office].street'],
            [self::LEVEL_1, 'address', 'address', 'street', 'office[street]', 'data.address[office].street.prop'],
            [self::LEVEL_1, 'address', 'address', 'street', 'office[street]', 'data.address[office][street]'],
            [self::LEVEL_1, 'address', 'address', 'street', 'office[street]', 'data.address[office][street].prop'],
            [self::LEVEL_0, 'address', 'address', 'street', 'office[street]', 'data[address].office.street'],
            [self::LEVEL_0, 'address', 'address', 'street', 'office[street]', 'data[address].office.street.prop'],
            [self::LEVEL_0, 'address', 'address', 'street', 'office[street]', 'data[address].office[street]'],
            [self::LEVEL_0, 'address', 'address', 'street', 'office[street]', 'data[address].office[street].prop'],
            [self::LEVEL_0, 'address', 'address', 'street', 'office[street]', 'data[address][office].street'],
            [self::LEVEL_0, 'address', 'address', 'street', 'office[street]', 'data[address][office].street.prop'],
            [self::LEVEL_0, 'address', 'address', 'street', 'office[street]', 'data[address][office][street]'],
            [self::LEVEL_0, 'address', 'address', 'street', 'office[street]', 'data[address][office][street].prop'],

            [self::LEVEL_2, 'address', '[address]', 'street', 'office[street]', 'children[address].children[street].data'],
            [self::LEVEL_2, 'address', '[address]', 'street', 'office[street]', 'children[address].children[street].data.prop'],
            [self::LEVEL_1, 'address', '[address]', 'street', 'office[street]', 'children[address].data.office.street'],
            [self::LEVEL_1, 'address', '[address]', 'street', 'office[street]', 'children[address].data.office.street.prop'],
            [self::LEVEL_2, 'address', '[address]', 'street', 'office[street]', 'children[address].data.office[street]'],
            [self::LEVEL_2, 'address', '[address]', 'street', 'office[street]', 'children[address].data.office[street].prop'],
            [self::LEVEL_1, 'address', '[address]', 'street', 'office[street]', 'children[address].data[office]'],
            [self::LEVEL_1, 'address', '[address]', 'street', 'office[street]', 'children[address].data[office].street'],
            [self::LEVEL_1, 'address', '[address]', 'street', 'office[street]', 'children[address].data[office].street.prop'],
            [self::LEVEL_1, 'address', '[address]', 'street', 'office[street]', 'children[address].data[office][street]'],
            [self::LEVEL_1, 'address', '[address]', 'street', 'office[street]', 'children[address].data[office][street].prop'],
            [self::LEVEL_0, 'address', '[address]', 'street', 'office[street]', 'data.address.office.street'],
            [self::LEVEL_0, 'address', '[address]', 'street', 'office[street]', 'data.address.office.street.prop'],
            [self::LEVEL_0, 'address', '[address]', 'street', 'office[street]', 'data.address.office[street]'],
            [self::LEVEL_0, 'address', '[address]', 'street', 'office[street]', 'data.address.office[street].prop'],
            [self::LEVEL_0, 'address', '[address]', 'street', 'office[street]', 'data.address[office].street'],
            [self::LEVEL_0, 'address', '[address]', 'street', 'office[street]', 'data.address[office].street.prop'],
            [self::LEVEL_0, 'address', '[address]', 'street', 'office[street]', 'data.address[office][street]'],
            [self::LEVEL_0, 'address', '[address]', 'street', 'office[street]', 'data.address[office][street].prop'],
            [self::LEVEL_1, 'address', '[address]', 'street', 'office[street]', 'data[address].office.street'],
            [self::LEVEL_1, 'address', '[address]', 'street', 'office[street]', 'data[address].office.street.prop'],
            [self::LEVEL_2, 'address', '[address]', 'street', 'office[street]', 'data[address].office[street]'],
            [self::LEVEL_2, 'address', '[address]', 'street', 'office[street]', 'data[address].office[street].prop'],
            [self::LEVEL_1, 'address', '[address]', 'street', 'office[street]', 'data[address][office].street'],
            [self::LEVEL_1, 'address', '[address]', 'street', 'office[street]', 'data[address][office].street.prop'],
            [self::LEVEL_1, 'address', '[address]', 'street', 'office[street]', 'data[address][office][street]'],
            [self::LEVEL_1, 'address', '[address]', 'street', 'office[street]', 'data[address][office][street].prop'],

            [self::LEVEL_2, 'address', 'address', 'street', '[office].street', 'children[address].children[street].data'],
            [self::LEVEL_2, 'address', 'address', 'street', '[office].street', 'children[address].children[street].data.prop'],
            [self::LEVEL_1, 'address', 'address', 'street', '[office].street', 'children[address].data'],
            [self::LEVEL_1, 'address', 'address', 'street', '[office].street', 'children[address].data.office'],
            [self::LEVEL_1, 'address', 'address', 'street', '[office].street', 'children[address].data.office.street'],
            [self::LEVEL_1, 'address', 'address', 'street', '[office].street', 'children[address].data.office.street.prop'],
            [self::LEVEL_1, 'address', 'address', 'street', '[office].street', 'children[address].data.office[street]'],
            [self::LEVEL_1, 'address', 'address', 'street', '[office].street', 'children[address].data.office[street].prop'],
            [self::LEVEL_1, 'address', 'address', 'street', '[office].street', 'children[address].data[office]'],
            [self::LEVEL_2, 'address', 'address', 'street', '[office].street', 'children[address].data[office].street'],
            [self::LEVEL_2, 'address', 'address', 'street', '[office].street', 'children[address].data[office].street.prop'],
            [self::LEVEL_1, 'address', 'address', 'street', '[office].street', 'children[address].data[office][street]'],
            [self::LEVEL_1, 'address', 'address', 'street', '[office].street', 'children[address].data[office][street].prop'],
            [self::LEVEL_1, 'address', 'address', 'street', '[office].street', 'data.address.office.street'],
            [self::LEVEL_1, 'address', 'address', 'street', '[office].street', 'data.address.office.street.prop'],
            [self::LEVEL_1, 'address', 'address', 'street', '[office].street', 'data.address.office[street]'],
            [self::LEVEL_1, 'address', 'address', 'street', '[office].street', 'data.address.office[street].prop'],
            [self::LEVEL_2, 'address', 'address', 'street', '[office].street', 'data.address[office].street'],
            [self::LEVEL_2, 'address', 'address', 'street', '[office].street', 'data.address[office].street.prop'],
            [self::LEVEL_1, 'address', 'address', 'street', '[office].street', 'data.address[office][street]'],
            [self::LEVEL_1, 'address', 'address', 'street', '[office].street', 'data.address[office][street].prop'],
            [self::LEVEL_0, 'address', 'address', 'street', '[office].street', 'data[address].office.street'],
            [self::LEVEL_0, 'address', 'address', 'street', '[office].street', 'data[address].office.street.prop'],
            [self::LEVEL_0, 'address', 'address', 'street', '[office].street', 'data[address].office[street]'],
            [self::LEVEL_0, 'address', 'address', 'street', '[office].street', 'data[address].office[street].prop'],
            [self::LEVEL_0, 'address', 'address', 'street', '[office].street', 'data[address][office].street'],
            [self::LEVEL_0, 'address', 'address', 'street', '[office].street', 'data[address][office].street.prop'],
            [self::LEVEL_0, 'address', 'address', 'street', '[office].street', 'data[address][office][street]'],
            [self::LEVEL_0, 'address', 'address', 'street', '[office].street', 'data[address][office][street].prop'],

            [self::LEVEL_2, 'address', '[address]', 'street', '[office].street', 'children[address].children[street].data'],
            [self::LEVEL_2, 'address', '[address]', 'street', '[office].street', 'children[address].children[street].data.prop'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[office].street', 'children[address].data'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[office].street', 'children[address].data.office'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[office].street', 'children[address].data.office.street'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[office].street', 'children[address].data.office.street.prop'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[office].street', 'children[address].data.office[street]'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[office].street', 'children[address].data.office[street].prop'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[office].street', 'children[address].data[office]'],
            [self::LEVEL_2, 'address', '[address]', 'street', '[office].street', 'children[address].data[office].street'],
            [self::LEVEL_2, 'address', '[address]', 'street', '[office].street', 'children[address].data[office].street.prop'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[office].street', 'children[address].data[office][street]'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[office].street', 'children[address].data[office][street].prop'],
            [self::LEVEL_0, 'address', '[address]', 'street', '[office].street', 'data.address.office.street'],
            [self::LEVEL_0, 'address', '[address]', 'street', '[office].street', 'data.address.office.street.prop'],
            [self::LEVEL_0, 'address', '[address]', 'street', '[office].street', 'data.address.office[street]'],
            [self::LEVEL_0, 'address', '[address]', 'street', '[office].street', 'data.address.office[street].prop'],
            [self::LEVEL_0, 'address', '[address]', 'street', '[office].street', 'data.address[office].street'],
            [self::LEVEL_0, 'address', '[address]', 'street', '[office].street', 'data.address[office].street.prop'],
            [self::LEVEL_0, 'address', '[address]', 'street', '[office].street', 'data.address[office][street]'],
            [self::LEVEL_0, 'address', '[address]', 'street', '[office].street', 'data.address[office][street].prop'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[office].street', 'data[address].office.street'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[office].street', 'data[address].office.street.prop'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[office].street', 'data[address].office[street]'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[office].street', 'data[address].office[street].prop'],
            [self::LEVEL_2, 'address', '[address]', 'street', '[office].street', 'data[address][office].street'],
            [self::LEVEL_2, 'address', '[address]', 'street', '[office].street', 'data[address][office].street.prop'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[office].street', 'data[address][office][street]'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[office].street', 'data[address][office][street].prop'],

            [self::LEVEL_2, 'address', 'address', 'street', '[office][street]', 'children[address].children[street].data'],
            [self::LEVEL_2, 'address', 'address', 'street', '[office][street]', 'children[address].children[street].data.prop'],
            [self::LEVEL_1, 'address', 'address', 'street', '[office][street]', 'children[address].data'],
            [self::LEVEL_1, 'address', 'address', 'street', '[office][street]', 'children[address].data.office'],
            [self::LEVEL_1, 'address', 'address', 'street', '[office][street]', 'children[address].data.office.street'],
            [self::LEVEL_1, 'address', 'address', 'street', '[office][street]', 'children[address].data.office.street.prop'],
            [self::LEVEL_1, 'address', 'address', 'street', '[office][street]', 'children[address].data.office[street]'],
            [self::LEVEL_1, 'address', 'address', 'street', '[office][street]', 'children[address].data.office[street].prop'],
            [self::LEVEL_1, 'address', 'address', 'street', '[office][street]', 'children[address].data[office]'],
            [self::LEVEL_1, 'address', 'address', 'street', '[office][street]', 'children[address].data[office].street'],
            [self::LEVEL_1, 'address', 'address', 'street', '[office][street]', 'children[address].data[office].street.prop'],
            [self::LEVEL_2, 'address', 'address', 'street', '[office][street]', 'children[address].data[office][street]'],
            [self::LEVEL_2, 'address', 'address', 'street', '[office][street]', 'children[address].data[office][street].prop'],
            [self::LEVEL_1, 'address', 'address', 'street', '[office][street]', 'data.address.office.street'],
            [self::LEVEL_1, 'address', 'address', 'street', '[office][street]', 'data.address.office.street.prop'],
            [self::LEVEL_1, 'address', 'address', 'street', '[office][street]', 'data.address.office[street]'],
            [self::LEVEL_1, 'address', 'address', 'street', '[office][street]', 'data.address.office[street].prop'],
            [self::LEVEL_1, 'address', 'address', 'street', '[office][street]', 'data.address[office].street'],
            [self::LEVEL_1, 'address', 'address', 'street', '[office][street]', 'data.address[office].street.prop'],
            [self::LEVEL_2, 'address', 'address', 'street', '[office][street]', 'data.address[office][street]'],
            [self::LEVEL_2, 'address', 'address', 'street', '[office][street]', 'data.address[office][street].prop'],
            [self::LEVEL_0, 'address', 'address', 'street', '[office][street]', 'data[address].office.street'],
            [self::LEVEL_0, 'address', 'address', 'street', '[office][street]', 'data[address].office.street.prop'],
            [self::LEVEL_0, 'address', 'address', 'street', '[office][street]', 'data[address].office[street]'],
            [self::LEVEL_0, 'address', 'address', 'street', '[office][street]', 'data[address].office[street].prop'],
            [self::LEVEL_0, 'address', 'address', 'street', '[office][street]', 'data[address][office].street'],
            [self::LEVEL_0, 'address', 'address', 'street', '[office][street]', 'data[address][office].street.prop'],
            [self::LEVEL_0, 'address', 'address', 'street', '[office][street]', 'data[address][office][street]'],
            [self::LEVEL_0, 'address', 'address', 'street', '[office][street]', 'data[address][office][street].prop'],

            [self::LEVEL_2, 'address', '[address]', 'street', '[office][street]', 'children[address].children[street].data'],
            [self::LEVEL_2, 'address', '[address]', 'street', '[office][street]', 'children[address].children[street].data.prop'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[office][street]', 'children[address].data'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[office][street]', 'children[address].data.office'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[office][street]', 'children[address].data.office.street'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[office][street]', 'children[address].data.office.street.prop'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[office][street]', 'children[address].data.office[street]'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[office][street]', 'children[address].data.office[street].prop'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[office][street]', 'children[address].data[office]'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[office][street]', 'children[address].data[office].street'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[office][street]', 'children[address].data[office].street.prop'],
            [self::LEVEL_2, 'address', '[address]', 'street', '[office][street]', 'children[address].data[office][street]'],
            [self::LEVEL_2, 'address', '[address]', 'street', '[office][street]', 'children[address].data[office][street].prop'],
            [self::LEVEL_0, 'address', '[address]', 'street', '[office][street]', 'data.address.office.street'],
            [self::LEVEL_0, 'address', '[address]', 'street', '[office][street]', 'data.address.office.street.prop'],
            [self::LEVEL_0, 'address', '[address]', 'street', '[office][street]', 'data.address.office[street]'],
            [self::LEVEL_0, 'address', '[address]', 'street', '[office][street]', 'data.address.office[street].prop'],
            [self::LEVEL_0, 'address', '[address]', 'street', '[office][street]', 'data.address[office].street'],
            [self::LEVEL_0, 'address', '[address]', 'street', '[office][street]', 'data.address[office].street.prop'],
            [self::LEVEL_0, 'address', '[address]', 'street', '[office][street]', 'data.address[office][street]'],
            [self::LEVEL_0, 'address', '[address]', 'street', '[office][street]', 'data.address[office][street].prop'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[office][street]', 'data[address].office.street'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[office][street]', 'data[address].office.street.prop'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[office][street]', 'data[address].office[street]'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[office][street]', 'data[address].office[street].prop'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[office][street]', 'data[address][office].street'],
            [self::LEVEL_1, 'address', '[address]', 'street', '[office][street]', 'data[address][office].street.prop'],
            [self::LEVEL_2, 'address', '[address]', 'street', '[office][street]', 'data[address][office][street]'],
            [self::LEVEL_2, 'address', '[address]', 'street', '[office][street]', 'data[address][office][street].prop'],

            // Edge cases which must not occur
            [self::LEVEL_2, 'address', 'address', 'street', 'street', 'children[address][street]'],
            [self::LEVEL_2, 'address', 'address', 'street', 'street', 'children[address][street].prop'],
            [self::LEVEL_2, 'address', 'address', 'street', '[street]', 'children[address][street]'],
            [self::LEVEL_2, 'address', 'address', 'street', '[street]', 'children[address][street].prop'],
            [self::LEVEL_2, 'address', '[address]', 'street', 'street', 'children[address][street]'],
            [self::LEVEL_2, 'address', '[address]', 'street', 'street', 'children[address][street].prop'],
            [self::LEVEL_2, 'address', '[address]', 'street', '[street]', 'children[address][street]'],
            [self::LEVEL_2, 'address', '[address]', 'street', '[street]', 'children[address][street].prop'],

            [self::LEVEL_0, 'address', 'person.address', 'street', 'street', 'children[person].children[address].children[street]'],
            [self::LEVEL_0, 'address', 'person.address', 'street', 'street', 'children[person].children[address].data.street'],
            [self::LEVEL_0, 'address', 'person.address', 'street', 'street', 'children[person].data.address.street'],
            [self::LEVEL_0, 'address', 'person.address', 'street', 'street', 'data.address.street'],

            [self::LEVEL_1, 'address', 'address', 'street', 'office.street', 'children[address].children[office].children[street]'],
            [self::LEVEL_1, 'address', 'address', 'street', 'office.street', 'children[address].children[office].data.street'],
            [self::LEVEL_1, 'address', 'address', 'street', 'office.street', 'children[address].data.street'],
            [self::LEVEL_1, 'address', 'address', 'street', 'office.street', 'data.address.street'],
        ];
    }

    /**
     * @dataProvider provideDefaultTests
     */
    public function testDefaultErrorMapping($target, $childName, $childPath, $grandChildName, $grandChildPath, $violationPath)
    {
        $violation = $this->getConstraintViolation($violationPath);
        $parent = $this->getForm('parent');
        $child = $this->getForm($childName, $childPath);
        $grandChild = $this->getForm($grandChildName, $grandChildPath);

        $parent->add($child);
        $child->add($grandChild);

        $parent->submit([]);

        $this->mapper->mapViolation($violation, $parent);

        if (self::LEVEL_0 === $target) {
            $this->assertEquals([$this->getFormError($violation, $parent)], iterator_to_array($parent->getErrors()), $parent->getName().' should have an error, but has none');
            $this->assertCount(0, $child->getErrors(), $childName.' should not have an error, but has one');
            $this->assertCount(0, $grandChild->getErrors(), $grandChildName.' should not have an error, but has one');
        } elseif (self::LEVEL_1 === $target) {
            $this->assertCount(0, $parent->getErrors(), $parent->getName().' should not have an error, but has one');
            $this->assertEquals([$this->getFormError($violation, $child)], iterator_to_array($child->getErrors()), $childName.' should have an error, but has none');
            $this->assertCount(0, $grandChild->getErrors(), $grandChildName.' should not have an error, but has one');
        } else {
            $this->assertCount(0, $parent->getErrors(), $parent->getName().' should not have an error, but has one');
            $this->assertCount(0, $child->getErrors(), $childName.' should not have an error, but has one');
            $this->assertEquals([$this->getFormError($violation, $grandChild)], iterator_to_array($grandChild->getErrors()), $grandChildName.' should have an error, but has none');
        }
    }

    public static function provideCustomDataErrorTests()
    {
        return [
            // mapping target, error mapping, child name, its property path, grand child name, its property path, violation path
            [self::LEVEL_1, 'foo', 'address', 'address', 'address', 'street', 'street', 'data.foo'],
            [self::LEVEL_1, 'foo', 'address', 'address', 'address', 'street', 'street', 'data.foo.prop'],
            [self::LEVEL_0, 'foo', 'address', 'address', 'address', 'street', 'street', 'data[foo]'],
            [self::LEVEL_0, 'foo', 'address', 'address', 'address', 'street', 'street', 'data[foo].prop'],

            [self::LEVEL_1, 'foo', 'address', 'address', 'address', 'street', 'street', 'data.address'],
            [self::LEVEL_1, 'foo', 'address', 'address', 'address', 'street', 'street', 'data.address.prop'],
            [self::LEVEL_0, 'foo', 'address', 'address', 'address', 'street', 'street', 'data[address]'],
            [self::LEVEL_0, 'foo', 'address', 'address', 'address', 'street', 'street', 'data[address].prop'],

            [self::LEVEL_1, 'foo', 'address', 'address', '[address]', 'street', 'street', 'data.foo'],
            [self::LEVEL_1, 'foo', 'address', 'address', '[address]', 'street', 'street', 'data.foo.prop'],
            [self::LEVEL_0, 'foo', 'address', 'address', '[address]', 'street', 'street', 'data[foo]'],
            [self::LEVEL_0, 'foo', 'address', 'address', '[address]', 'street', 'street', 'data[foo].prop'],

            [self::LEVEL_0, 'foo', 'address', 'address', '[address]', 'street', 'street', 'data.address'],
            [self::LEVEL_0, 'foo', 'address', 'address', '[address]', 'street', 'street', 'data.address.prop'],
            [self::LEVEL_1, 'foo', 'address', 'address', '[address]', 'street', 'street', 'data[address]'],
            [self::LEVEL_1, 'foo', 'address', 'address', '[address]', 'street', 'street', 'data[address].prop'],

            [self::LEVEL_0, '[foo]', 'address', 'address', 'address', 'street', 'street', 'data.foo'],
            [self::LEVEL_0, '[foo]', 'address', 'address', 'address', 'street', 'street', 'data.foo.prop'],
            [self::LEVEL_1, '[foo]', 'address', 'address', 'address', 'street', 'street', 'data[foo]'],
            [self::LEVEL_1, '[foo]', 'address', 'address', 'address', 'street', 'street', 'data[foo].prop'],

            [self::LEVEL_1, '[foo]', 'address', 'address', 'address', 'street', 'street', 'data.address'],
            [self::LEVEL_1, '[foo]', 'address', 'address', 'address', 'street', 'street', 'data.address.prop'],
            [self::LEVEL_0, '[foo]', 'address', 'address', 'address', 'street', 'street', 'data[address]'],
            [self::LEVEL_0, '[foo]', 'address', 'address', 'address', 'street', 'street', 'data[address].prop'],

            [self::LEVEL_0, '[foo]', 'address', 'address', '[address]', 'street', 'street', 'data.foo'],
            [self::LEVEL_0, '[foo]', 'address', 'address', '[address]', 'street', 'street', 'data.foo.prop'],
            [self::LEVEL_1, '[foo]', 'address', 'address', '[address]', 'street', 'street', 'data[foo]'],
            [self::LEVEL_1, '[foo]', 'address', 'address', '[address]', 'street', 'street', 'data[foo].prop'],

            [self::LEVEL_0, '[foo]', 'address', 'address', '[address]', 'street', 'street', 'data.address'],
            [self::LEVEL_0, '[foo]', 'address', 'address', '[address]', 'street', 'street', 'data.address.prop'],
            [self::LEVEL_1, '[foo]', 'address', 'address', '[address]', 'street', 'street', 'data[address]'],
            [self::LEVEL_1, '[foo]', 'address', 'address', '[address]', 'street', 'street', 'data[address].prop'],

            [self::LEVEL_2, 'foo', 'address', 'address', 'address', 'street', 'street', 'data.foo.street'],
            [self::LEVEL_2, 'foo', 'address', 'address', 'address', 'street', 'street', 'data.foo.street.prop'],
            [self::LEVEL_1, 'foo', 'address', 'address', 'address', 'street', 'street', 'data.foo[street]'],
            [self::LEVEL_1, 'foo', 'address', 'address', 'address', 'street', 'street', 'data.foo[street].prop'],
            [self::LEVEL_0, 'foo', 'address', 'address', 'address', 'street', 'street', 'data[foo].street'],
            [self::LEVEL_0, 'foo', 'address', 'address', 'address', 'street', 'street', 'data[foo].street.prop'],
            [self::LEVEL_0, 'foo', 'address', 'address', 'address', 'street', 'street', 'data[foo][street]'],
            [self::LEVEL_0, 'foo', 'address', 'address', 'address', 'street', 'street', 'data[foo][street].prop'],

            [self::LEVEL_2, 'foo', 'address', 'address', 'address', 'street', 'street', 'data.address.street'],
            [self::LEVEL_2, 'foo', 'address', 'address', 'address', 'street', 'street', 'data.address.street.prop'],
            [self::LEVEL_1, 'foo', 'address', 'address', 'address', 'street', 'street', 'data.address[street]'],
            [self::LEVEL_1, 'foo', 'address', 'address', 'address', 'street', 'street', 'data.address[street].prop'],
            [self::LEVEL_0, 'foo', 'address', 'address', 'address', 'street', 'street', 'data[address].street'],
            [self::LEVEL_0, 'foo', 'address', 'address', 'address', 'street', 'street', 'data[address].street.prop'],
            [self::LEVEL_0, 'foo', 'address', 'address', 'address', 'street', 'street', 'data[address][street]'],
            [self::LEVEL_0, 'foo', 'address', 'address', 'address', 'street', 'street', 'data[address][street].prop'],

            [self::LEVEL_1, 'foo', 'address', 'address', 'address', 'street', '[street]', 'data.foo.street'],
            [self::LEVEL_1, 'foo', 'address', 'address', 'address', 'street', '[street]', 'data.foo.street.prop'],
            [self::LEVEL_2, 'foo', 'address', 'address', 'address', 'street', '[street]', 'data.foo[street]'],
            [self::LEVEL_2, 'foo', 'address', 'address', 'address', 'street', '[street]', 'data.foo[street].prop'],
            [self::LEVEL_0, 'foo', 'address', 'address', 'address', 'street', '[street]', 'data[foo].street'],
            [self::LEVEL_0, 'foo', 'address', 'address', 'address', 'street', '[street]', 'data[foo].street.prop'],
            [self::LEVEL_0, 'foo', 'address', 'address', 'address', 'street', '[street]', 'data[foo][street]'],
            [self::LEVEL_0, 'foo', 'address', 'address', 'address', 'street', '[street]', 'data[foo][street].prop'],

            [self::LEVEL_1, 'foo', 'address', 'address', 'address', 'street', '[street]', 'data.address.street'],
            [self::LEVEL_1, 'foo', 'address', 'address', 'address', 'street', '[street]', 'data.address.street.prop'],
            [self::LEVEL_2, 'foo', 'address', 'address', 'address', 'street', '[street]', 'data.address[street]'],
            [self::LEVEL_2, 'foo', 'address', 'address', 'address', 'street', '[street]', 'data.address[street].prop'],
            [self::LEVEL_0, 'foo', 'address', 'address', 'address', 'street', '[street]', 'data[address].street'],
            [self::LEVEL_0, 'foo', 'address', 'address', 'address', 'street', '[street]', 'data[address].street.prop'],
            [self::LEVEL_0, 'foo', 'address', 'address', 'address', 'street', '[street]', 'data[address][street]'],
            [self::LEVEL_0, 'foo', 'address', 'address', 'address', 'street', '[street]', 'data[address][street].prop'],

            [self::LEVEL_2, 'foo', 'address', 'address', '[address]', 'street', 'street', 'data.foo.street'],
            [self::LEVEL_2, 'foo', 'address', 'address', '[address]', 'street', 'street', 'data.foo.street.prop'],
            [self::LEVEL_1, 'foo', 'address', 'address', '[address]', 'street', 'street', 'data.foo[street]'],
            [self::LEVEL_1, 'foo', 'address', 'address', '[address]', 'street', 'street', 'data.foo[street].prop'],
            [self::LEVEL_0, 'foo', 'address', 'address', '[address]', 'street', 'street', 'data[foo].street'],
            [self::LEVEL_0, 'foo', 'address', 'address', '[address]', 'street', 'street', 'data[foo].street.prop'],
            [self::LEVEL_0, 'foo', 'address', 'address', '[address]', 'street', 'street', 'data[foo][street]'],
            [self::LEVEL_0, 'foo', 'address', 'address', '[address]', 'street', 'street', 'data[foo][street].prop'],

            [self::LEVEL_0, 'foo', 'address', 'address', '[address]', 'street', 'street', 'data.address.street'],
            [self::LEVEL_0, 'foo', 'address', 'address', '[address]', 'street', 'street', 'data.address.street.prop'],
            [self::LEVEL_0, 'foo', 'address', 'address', '[address]', 'street', 'street', 'data.address[street]'],
            [self::LEVEL_0, 'foo', 'address', 'address', '[address]', 'street', 'street', 'data.address[street].prop'],
            [self::LEVEL_2, 'foo', 'address', 'address', '[address]', 'street', 'street', 'data[address].street'],
            [self::LEVEL_2, 'foo', 'address', 'address', '[address]', 'street', 'street', 'data[address].street.prop'],
            [self::LEVEL_1, 'foo', 'address', 'address', '[address]', 'street', 'street', 'data[address][street]'],
            [self::LEVEL_1, 'foo', 'address', 'address', '[address]', 'street', 'street', 'data[address][street].prop'],

            [self::LEVEL_1, 'foo', 'address', 'address', '[address]', 'street', '[street]', 'data.foo.street'],
            [self::LEVEL_1, 'foo', 'address', 'address', '[address]', 'street', '[street]', 'data.foo.street.prop'],
            [self::LEVEL_2, 'foo', 'address', 'address', '[address]', 'street', '[street]', 'data.foo[street]'],
            [self::LEVEL_2, 'foo', 'address', 'address', '[address]', 'street', '[street]', 'data.foo[street].prop'],
            [self::LEVEL_0, 'foo', 'address', 'address', '[address]', 'street', '[street]', 'data[foo].street'],
            [self::LEVEL_0, 'foo', 'address', 'address', '[address]', 'street', '[street]', 'data[foo].street.prop'],
            [self::LEVEL_0, 'foo', 'address', 'address', '[address]', 'street', '[street]', 'data[foo][street]'],
            [self::LEVEL_0, 'foo', 'address', 'address', '[address]', 'street', '[street]', 'data[foo][street].prop'],

            [self::LEVEL_0, 'foo', 'address', 'address', '[address]', 'street', '[street]', 'data.address.street'],
            [self::LEVEL_0, 'foo', 'address', 'address', '[address]', 'street', '[street]', 'data.address.street.prop'],
            [self::LEVEL_0, 'foo', 'address', 'address', '[address]', 'street', '[street]', 'data.address[street]'],
            [self::LEVEL_0, 'foo', 'address', 'address', '[address]', 'street', '[street]', 'data.address[street].prop'],
            [self::LEVEL_1, 'foo', 'address', 'address', '[address]', 'street', '[street]', 'data[address].street'],
            [self::LEVEL_1, 'foo', 'address', 'address', '[address]', 'street', '[street]', 'data[address].street.prop'],
            [self::LEVEL_2, 'foo', 'address', 'address', '[address]', 'street', '[street]', 'data[address][street]'],
            [self::LEVEL_2, 'foo', 'address', 'address', '[address]', 'street', '[street]', 'data[address][street].prop'],

            [self::LEVEL_0, '[foo]', 'address', 'address', 'address', 'street', 'street', 'data.foo.street'],
            [self::LEVEL_0, '[foo]', 'address', 'address', 'address', 'street', 'street', 'data.foo.street.prop'],
            [self::LEVEL_0, '[foo]', 'address', 'address', 'address', 'street', 'street', 'data.foo[street]'],
            [self::LEVEL_0, '[foo]', 'address', 'address', 'address', 'street', 'street', 'data.foo[street].prop'],
            [self::LEVEL_2, '[foo]', 'address', 'address', 'address', 'street', 'street', 'data[foo].street'],
            [self::LEVEL_2, '[foo]', 'address', 'address', 'address', 'street', 'street', 'data[foo].street.prop'],
            [self::LEVEL_1, '[foo]', 'address', 'address', 'address', 'street', 'street', 'data[foo][street]'],
            [self::LEVEL_1, '[foo]', 'address', 'address', 'address', 'street', 'street', 'data[foo][street].prop'],

            [self::LEVEL_2, '[foo]', 'address', 'address', 'address', 'street', 'street', 'data.address.street'],
            [self::LEVEL_2, '[foo]', 'address', 'address', 'address', 'street', 'street', 'data.address.street.prop'],
            [self::LEVEL_1, '[foo]', 'address', 'address', 'address', 'street', 'street', 'data.address[street]'],
            [self::LEVEL_1, '[foo]', 'address', 'address', 'address', 'street', 'street', 'data.address[street].prop'],
            [self::LEVEL_0, '[foo]', 'address', 'address', 'address', 'street', 'street', 'data[address].street'],
            [self::LEVEL_0, '[foo]', 'address', 'address', 'address', 'street', 'street', 'data[address].street.prop'],
            [self::LEVEL_0, '[foo]', 'address', 'address', 'address', 'street', 'street', 'data[address][street]'],
            [self::LEVEL_0, '[foo]', 'address', 'address', 'address', 'street', 'street', 'data[address][street].prop'],

            [self::LEVEL_0, '[foo]', 'address', 'address', 'address', 'street', '[street]', 'data.foo.street'],
            [self::LEVEL_0, '[foo]', 'address', 'address', 'address', 'street', '[street]', 'data.foo.street.prop'],
            [self::LEVEL_0, '[foo]', 'address', 'address', 'address', 'street', '[street]', 'data.foo[street]'],
            [self::LEVEL_0, '[foo]', 'address', 'address', 'address', 'street', '[street]', 'data.foo[street].prop'],
            [self::LEVEL_1, '[foo]', 'address', 'address', 'address', 'street', '[street]', 'data[foo].street'],
            [self::LEVEL_1, '[foo]', 'address', 'address', 'address', 'street', '[street]', 'data[foo].street.prop'],
            [self::LEVEL_2, '[foo]', 'address', 'address', 'address', 'street', '[street]', 'data[foo][street]'],
            [self::LEVEL_2, '[foo]', 'address', 'address', 'address', 'street', '[street]', 'data[foo][street].prop'],

            [self::LEVEL_1, '[foo]', 'address', 'address', 'address', 'street', '[street]', 'data.address.street'],
            [self::LEVEL_1, '[foo]', 'address', 'address', 'address', 'street', '[street]', 'data.address.street.prop'],
            [self::LEVEL_2, '[foo]', 'address', 'address', 'address', 'street', '[street]', 'data.address[street]'],
            [self::LEVEL_2, '[foo]', 'address', 'address', 'address', 'street', '[street]', 'data.address[street].prop'],
            [self::LEVEL_0, '[foo]', 'address', 'address', 'address', 'street', '[street]', 'data[address].street'],
            [self::LEVEL_0, '[foo]', 'address', 'address', 'address', 'street', '[street]', 'data[address].street.prop'],
            [self::LEVEL_0, '[foo]', 'address', 'address', 'address', 'street', '[street]', 'data[address][street]'],
            [self::LEVEL_0, '[foo]', 'address', 'address', 'address', 'street', '[street]', 'data[address][street].prop'],

            [self::LEVEL_0, '[foo]', 'address', 'address', '[address]', 'street', 'street', 'data.foo.street'],
            [self::LEVEL_0, '[foo]', 'address', 'address', '[address]', 'street', 'street', 'data.foo.street.prop'],
            [self::LEVEL_0, '[foo]', 'address', 'address', '[address]', 'street', 'street', 'data.foo[street]'],
            [self::LEVEL_0, '[foo]', 'address', 'address', '[address]', 'street', 'street', 'data.foo[street].prop'],
            [self::LEVEL_2, '[foo]', 'address', 'address', '[address]', 'street', 'street', 'data[foo].street'],
            [self::LEVEL_2, '[foo]', 'address', 'address', '[address]', 'street', 'street', 'data[foo].street.prop'],
            [self::LEVEL_1, '[foo]', 'address', 'address', '[address]', 'street', 'street', 'data[foo][street]'],
            [self::LEVEL_1, '[foo]', 'address', 'address', '[address]', 'street', 'street', 'data[foo][street].prop'],

            [self::LEVEL_0, '[foo]', 'address', 'address', '[address]', 'street', 'street', 'data.address.street'],
            [self::LEVEL_0, '[foo]', 'address', 'address', '[address]', 'street', 'street', 'data.address.street.prop'],
            [self::LEVEL_0, '[foo]', 'address', 'address', '[address]', 'street', 'street', 'data.address[street]'],
            [self::LEVEL_0, '[foo]', 'address', 'address', '[address]', 'street', 'street', 'data.address[street].prop'],
            [self::LEVEL_2, '[foo]', 'address', 'address', '[address]', 'street', 'street', 'data[address].street'],
            [self::LEVEL_2, '[foo]', 'address', 'address', '[address]', 'street', 'street', 'data[address].street.prop'],
            [self::LEVEL_1, '[foo]', 'address', 'address', '[address]', 'street', 'street', 'data[address][street]'],
            [self::LEVEL_1, '[foo]', 'address', 'address', '[address]', 'street', 'street', 'data[address][street].prop'],

            [self::LEVEL_0, '[foo]', 'address', 'address', '[address]', 'street', '[street]', 'data.foo.street'],
            [self::LEVEL_0, '[foo]', 'address', 'address', '[address]', 'street', '[street]', 'data.foo.street.prop'],
            [self::LEVEL_0, '[foo]', 'address', 'address', '[address]', 'street', '[street]', 'data.foo[street]'],
            [self::LEVEL_0, '[foo]', 'address', 'address', '[address]', 'street', '[street]', 'data.foo[street].prop'],
            [self::LEVEL_1, '[foo]', 'address', 'address', '[address]', 'street', '[street]', 'data[foo].street'],
            [self::LEVEL_1, '[foo]', 'address', 'address', '[address]', 'street', '[street]', 'data[foo].street.prop'],
            [self::LEVEL_2, '[foo]', 'address', 'address', '[address]', 'street', '[street]', 'data[foo][street]'],
            [self::LEVEL_2, '[foo]', 'address', 'address', '[address]', 'street', '[street]', 'data[foo][street].prop'],

            [self::LEVEL_0, '[foo]', 'address', 'address', '[address]', 'street', '[street]', 'data.address.street'],
            [self::LEVEL_0, '[foo]', 'address', 'address', '[address]', 'street', '[street]', 'data.address.street.prop'],
            [self::LEVEL_0, '[foo]', 'address', 'address', '[address]', 'street', '[street]', 'data.address[street]'],
            [self::LEVEL_0, '[foo]', 'address', 'address', '[address]', 'street', '[street]', 'data.address[street].prop'],
            [self::LEVEL_1, '[foo]', 'address', 'address', '[address]', 'street', '[street]', 'data[address].street'],
            [self::LEVEL_1, '[foo]', 'address', 'address', '[address]', 'street', '[street]', 'data[address].street.prop'],
            [self::LEVEL_2, '[foo]', 'address', 'address', '[address]', 'street', '[street]', 'data[address][street]'],
            [self::LEVEL_2, '[foo]', 'address', 'address', '[address]', 'street', '[street]', 'data[address][street].prop'],

            [self::LEVEL_1, 'foo.bar', 'address', 'address', 'address', 'street', 'street', 'data.foo.bar'],
            [self::LEVEL_1, 'foo.bar', 'address', 'address', 'address', 'street', 'street', 'data.foo.bar.prop'],
            [self::LEVEL_0, 'foo.bar', 'address', 'address', 'address', 'street', 'street', 'data.foo[bar]'],
            [self::LEVEL_0, 'foo.bar', 'address', 'address', 'address', 'street', 'street', 'data.foo[bar].prop'],
            [self::LEVEL_0, 'foo.bar', 'address', 'address', 'address', 'street', 'street', 'data[foo].bar'],
            [self::LEVEL_0, 'foo.bar', 'address', 'address', 'address', 'street', 'street', 'data[foo].bar.prop'],
            [self::LEVEL_0, 'foo.bar', 'address', 'address', 'address', 'street', 'street', 'data[foo][bar]'],
            [self::LEVEL_0, 'foo.bar', 'address', 'address', 'address', 'street', 'street', 'data[foo][bar].prop'],

            [self::LEVEL_0, 'foo[bar]', 'address', 'address', 'address', 'street', 'street', 'data.foo.bar'],
            [self::LEVEL_0, 'foo[bar]', 'address', 'address', 'address', 'street', 'street', 'data.foo.bar.prop'],
            [self::LEVEL_1, 'foo[bar]', 'address', 'address', 'address', 'street', 'street', 'data.foo[bar]'],
            [self::LEVEL_1, 'foo[bar]', 'address', 'address', 'address', 'street', 'street', 'data.foo[bar].prop'],
            [self::LEVEL_0, 'foo[bar]', 'address', 'address', 'address', 'street', 'street', 'data[foo].bar'],
            [self::LEVEL_0, 'foo[bar]', 'address', 'address', 'address', 'street', 'street', 'data[foo].bar.prop'],
            [self::LEVEL_0, 'foo[bar]', 'address', 'address', 'address', 'street', 'street', 'data[foo][bar]'],
            [self::LEVEL_0, 'foo[bar]', 'address', 'address', 'address', 'street', 'street', 'data[foo][bar].prop'],

            [self::LEVEL_0, '[foo].bar', 'address', 'address', 'address', 'street', 'street', 'data.foo.bar'],
            [self::LEVEL_0, '[foo].bar', 'address', 'address', 'address', 'street', 'street', 'data.foo.bar.prop'],
            [self::LEVEL_0, '[foo].bar', 'address', 'address', 'address', 'street', 'street', 'data.foo[bar]'],
            [self::LEVEL_0, '[foo].bar', 'address', 'address', 'address', 'street', 'street', 'data.foo[bar].prop'],
            [self::LEVEL_1, '[foo].bar', 'address', 'address', 'address', 'street', 'street', 'data[foo].bar'],
            [self::LEVEL_1, '[foo].bar', 'address', 'address', 'address', 'street', 'street', 'data[foo].bar.prop'],
            [self::LEVEL_0, '[foo].bar', 'address', 'address', 'address', 'street', 'street', 'data[foo][bar]'],
            [self::LEVEL_0, '[foo].bar', 'address', 'address', 'address', 'street', 'street', 'data[foo][bar].prop'],

            [self::LEVEL_0, '[foo][bar]', 'address', 'address', 'address', 'street', 'street', 'data.foo.bar'],
            [self::LEVEL_0, '[foo][bar]', 'address', 'address', 'address', 'street', 'street', 'data.foo.bar.prop'],
            [self::LEVEL_0, '[foo][bar]', 'address', 'address', 'address', 'street', 'street', 'data.foo[bar]'],
            [self::LEVEL_0, '[foo][bar]', 'address', 'address', 'address', 'street', 'street', 'data.foo[bar].prop'],
            [self::LEVEL_0, '[foo][bar]', 'address', 'address', 'address', 'street', 'street', 'data[foo].bar'],
            [self::LEVEL_0, '[foo][bar]', 'address', 'address', 'address', 'street', 'street', 'data[foo].bar.prop'],
            [self::LEVEL_1, '[foo][bar]', 'address', 'address', 'address', 'street', 'street', 'data[foo][bar]'],
            [self::LEVEL_1, '[foo][bar]', 'address', 'address', 'address', 'street', 'street', 'data[foo][bar].prop'],

            [self::LEVEL_2, 'foo.bar', 'address', 'address', 'address', 'street', 'street', 'data.foo.bar.street'],
            [self::LEVEL_2, 'foo.bar', 'address', 'address', 'address', 'street', 'street', 'data.foo.bar.street.prop'],
            [self::LEVEL_1, 'foo.bar', 'address', 'address', 'address', 'street', 'street', 'data.foo.bar[street]'],
            [self::LEVEL_1, 'foo.bar', 'address', 'address', 'address', 'street', 'street', 'data.foo.bar[street].prop'],
            [self::LEVEL_0, 'foo.bar', 'address', 'address', 'address', 'street', 'street', 'data.foo[bar].street'],
            [self::LEVEL_0, 'foo.bar', 'address', 'address', 'address', 'street', 'street', 'data.foo[bar].street.prop'],
            [self::LEVEL_0, 'foo.bar', 'address', 'address', 'address', 'street', 'street', 'data.foo[bar][street]'],
            [self::LEVEL_0, 'foo.bar', 'address', 'address', 'address', 'street', 'street', 'data.foo[bar][street].prop'],
            [self::LEVEL_0, 'foo.bar', 'address', 'address', 'address', 'street', 'street', 'data[foo].bar.street'],
            [self::LEVEL_0, 'foo.bar', 'address', 'address', 'address', 'street', 'street', 'data[foo].bar.street.prop'],
            [self::LEVEL_0, 'foo.bar', 'address', 'address', 'address', 'street', 'street', 'data[foo].bar[street]'],
            [self::LEVEL_0, 'foo.bar', 'address', 'address', 'address', 'street', 'street', 'data[foo].bar[street].prop'],
            [self::LEVEL_0, 'foo.bar', 'address', 'address', 'address', 'street', 'street', 'data[foo][bar].street'],
            [self::LEVEL_0, 'foo.bar', 'address', 'address', 'address', 'street', 'street', 'data[foo][bar].street.prop'],
            [self::LEVEL_0, 'foo.bar', 'address', 'address', 'address', 'street', 'street', 'data[foo][bar][street]'],
            [self::LEVEL_0, 'foo.bar', 'address', 'address', 'address', 'street', 'street', 'data[foo][bar][street].prop'],

            [self::LEVEL_1, 'foo.bar', 'address', 'address', 'address', 'street', '[street]', 'data.foo.bar.street'],
            [self::LEVEL_1, 'foo.bar', 'address', 'address', 'address', 'street', '[street]', 'data.foo.bar.street.prop'],
            [self::LEVEL_2, 'foo.bar', 'address', 'address', 'address', 'street', '[street]', 'data.foo.bar[street]'],
            [self::LEVEL_2, 'foo.bar', 'address', 'address', 'address', 'street', '[street]', 'data.foo.bar[street].prop'],
            [self::LEVEL_0, 'foo.bar', 'address', 'address', 'address', 'street', '[street]', 'data.foo[bar].street'],
            [self::LEVEL_0, 'foo.bar', 'address', 'address', 'address', 'street', '[street]', 'data.foo[bar].street.prop'],
            [self::LEVEL_0, 'foo.bar', 'address', 'address', 'address', 'street', '[street]', 'data.foo[bar][street]'],
            [self::LEVEL_0, 'foo.bar', 'address', 'address', 'address', 'street', '[street]', 'data.foo[bar][street].prop'],
            [self::LEVEL_0, 'foo.bar', 'address', 'address', 'address', 'street', '[street]', 'data[foo].bar.street'],
            [self::LEVEL_0, 'foo.bar', 'address', 'address', 'address', 'street', '[street]', 'data[foo].bar.street.prop'],
            [self::LEVEL_0, 'foo.bar', 'address', 'address', 'address', 'street', '[street]', 'data[foo].bar[street]'],
            [self::LEVEL_0, 'foo.bar', 'address', 'address', 'address', 'street', '[street]', 'data[foo].bar[street].prop'],
            [self::LEVEL_0, 'foo.bar', 'address', 'address', 'address', 'street', '[street]', 'data[foo][bar].street'],
            [self::LEVEL_0, 'foo.bar', 'address', 'address', 'address', 'street', '[street]', 'data[foo][bar].street.prop'],
            [self::LEVEL_0, 'foo.bar', 'address', 'address', 'address', 'street', '[street]', 'data[foo][bar][street]'],
            [self::LEVEL_0, 'foo.bar', 'address', 'address', 'address', 'street', '[street]', 'data[foo][bar][street].prop'],

            [self::LEVEL_0, 'foo[bar]', 'address', 'address', 'address', 'street', 'street', 'data.foo.bar.street'],
            [self::LEVEL_0, 'foo[bar]', 'address', 'address', 'address', 'street', 'street', 'data.foo.bar.street.prop'],
            [self::LEVEL_0, 'foo[bar]', 'address', 'address', 'address', 'street', 'street', 'data.foo.bar[street]'],
            [self::LEVEL_0, 'foo[bar]', 'address', 'address', 'address', 'street', 'street', 'data.foo.bar[street].prop'],
            [self::LEVEL_2, 'foo[bar]', 'address', 'address', 'address', 'street', 'street', 'data.foo[bar].street'],
            [self::LEVEL_2, 'foo[bar]', 'address', 'address', 'address', 'street', 'street', 'data.foo[bar].street.prop'],
            [self::LEVEL_1, 'foo[bar]', 'address', 'address', 'address', 'street', 'street', 'data.foo[bar][street]'],
            [self::LEVEL_1, 'foo[bar]', 'address', 'address', 'address', 'street', 'street', 'data.foo[bar][street].prop'],
            [self::LEVEL_0, 'foo[bar]', 'address', 'address', 'address', 'street', 'street', 'data[foo].bar.street'],
            [self::LEVEL_0, 'foo[bar]', 'address', 'address', 'address', 'street', 'street', 'data[foo].bar.street.prop'],
            [self::LEVEL_0, 'foo[bar]', 'address', 'address', 'address', 'street', 'street', 'data[foo].bar[street]'],
            [self::LEVEL_0, 'foo[bar]', 'address', 'address', 'address', 'street', 'street', 'data[foo].bar[street].prop'],
            [self::LEVEL_0, 'foo[bar]', 'address', 'address', 'address', 'street', 'street', 'data[foo][bar].street'],
            [self::LEVEL_0, 'foo[bar]', 'address', 'address', 'address', 'street', 'street', 'data[foo][bar].street.prop'],
            [self::LEVEL_0, 'foo[bar]', 'address', 'address', 'address', 'street', 'street', 'data[foo][bar][street]'],
            [self::LEVEL_0, 'foo[bar]', 'address', 'address', 'address', 'street', 'street', 'data[foo][bar][street].prop'],

            [self::LEVEL_0, 'foo[bar]', 'address', 'address', 'address', 'street', '[street]', 'data.foo.bar.street'],
            [self::LEVEL_0, 'foo[bar]', 'address', 'address', 'address', 'street', '[street]', 'data.foo.bar.street.prop'],
            [self::LEVEL_0, 'foo[bar]', 'address', 'address', 'address', 'street', '[street]', 'data.foo.bar[street]'],
            [self::LEVEL_0, 'foo[bar]', 'address', 'address', 'address', 'street', '[street]', 'data.foo.bar[street].prop'],
            [self::LEVEL_1, 'foo[bar]', 'address', 'address', 'address', 'street', '[street]', 'data.foo[bar].street'],
            [self::LEVEL_1, 'foo[bar]', 'address', 'address', 'address', 'street', '[street]', 'data.foo[bar].street.prop'],
            [self::LEVEL_2, 'foo[bar]', 'address', 'address', 'address', 'street', '[street]', 'data.foo[bar][street]'],
            [self::LEVEL_2, 'foo[bar]', 'address', 'address', 'address', 'street', '[street]', 'data.foo[bar][street].prop'],
            [self::LEVEL_0, 'foo[bar]', 'address', 'address', 'address', 'street', '[street]', 'data[foo].bar.street'],
            [self::LEVEL_0, 'foo[bar]', 'address', 'address', 'address', 'street', '[street]', 'data[foo].bar.street.prop'],
            [self::LEVEL_0, 'foo[bar]', 'address', 'address', 'address', 'street', '[street]', 'data[foo].bar[street]'],
            [self::LEVEL_0, 'foo[bar]', 'address', 'address', 'address', 'street', '[street]', 'data[foo].bar[street].prop'],
            [self::LEVEL_0, 'foo[bar]', 'address', 'address', 'address', 'street', '[street]', 'data[foo][bar].street'],
            [self::LEVEL_0, 'foo[bar]', 'address', 'address', 'address', 'street', '[street]', 'data[foo][bar].street.prop'],
            [self::LEVEL_0, 'foo[bar]', 'address', 'address', 'address', 'street', '[street]', 'data[foo][bar][street]'],
            [self::LEVEL_0, 'foo[bar]', 'address', 'address', 'address', 'street', '[street]', 'data[foo][bar][street].prop'],

            [self::LEVEL_0, '[foo].bar', 'address', 'address', 'address', 'street', 'street', 'data.foo.bar.street'],
            [self::LEVEL_0, '[foo].bar', 'address', 'address', 'address', 'street', 'street', 'data.foo.bar.street.prop'],
            [self::LEVEL_0, '[foo].bar', 'address', 'address', 'address', 'street', 'street', 'data.foo.bar[street]'],
            [self::LEVEL_0, '[foo].bar', 'address', 'address', 'address', 'street', 'street', 'data.foo.bar[street].prop'],
            [self::LEVEL_0, '[foo].bar', 'address', 'address', 'address', 'street', 'street', 'data.foo[bar].street'],
            [self::LEVEL_0, '[foo].bar', 'address', 'address', 'address', 'street', 'street', 'data.foo[bar].street.prop'],
            [self::LEVEL_0, '[foo].bar', 'address', 'address', 'address', 'street', 'street', 'data.foo[bar][street]'],
            [self::LEVEL_0, '[foo].bar', 'address', 'address', 'address', 'street', 'street', 'data.foo[bar][street].prop'],
            [self::LEVEL_2, '[foo].bar', 'address', 'address', 'address', 'street', 'street', 'data[foo].bar.street'],
            [self::LEVEL_2, '[foo].bar', 'address', 'address', 'address', 'street', 'street', 'data[foo].bar.street.prop'],
            [self::LEVEL_1, '[foo].bar', 'address', 'address', 'address', 'street', 'street', 'data[foo].bar[street]'],
            [self::LEVEL_1, '[foo].bar', 'address', 'address', 'address', 'street', 'street', 'data[foo].bar[street].prop'],
            [self::LEVEL_0, '[foo].bar', 'address', 'address', 'address', 'street', 'street', 'data[foo][bar].street'],
            [self::LEVEL_0, '[foo].bar', 'address', 'address', 'address', 'street', 'street', 'data[foo][bar].street.prop'],
            [self::LEVEL_0, '[foo].bar', 'address', 'address', 'address', 'street', 'street', 'data[foo][bar][street]'],
            [self::LEVEL_0, '[foo].bar', 'address', 'address', 'address', 'street', 'street', 'data[foo][bar][street].prop'],

            [self::LEVEL_0, '[foo].bar', 'address', 'address', 'address', 'street', '[street]', 'data.foo.bar.street'],
            [self::LEVEL_0, '[foo].bar', 'address', 'address', 'address', 'street', '[street]', 'data.foo.bar.street.prop'],
            [self::LEVEL_0, '[foo].bar', 'address', 'address', 'address', 'street', '[street]', 'data.foo.bar[street]'],
            [self::LEVEL_0, '[foo].bar', 'address', 'address', 'address', 'street', '[street]', 'data.foo.bar[street].prop'],
            [self::LEVEL_0, '[foo].bar', 'address', 'address', 'address', 'street', '[street]', 'data.foo[bar].street'],
            [self::LEVEL_0, '[foo].bar', 'address', 'address', 'address', 'street', '[street]', 'data.foo[bar].street.prop'],
            [self::LEVEL_0, '[foo].bar', 'address', 'address', 'address', 'street', '[street]', 'data.foo[bar][street]'],
            [self::LEVEL_0, '[foo].bar', 'address', 'address', 'address', 'street', '[street]', 'data.foo[bar][street].prop'],
            [self::LEVEL_1, '[foo].bar', 'address', 'address', 'address', 'street', '[street]', 'data[foo].bar.street'],
            [self::LEVEL_1, '[foo].bar', 'address', 'address', 'address', 'street', '[street]', 'data[foo].bar.street.prop'],
            [self::LEVEL_2, '[foo].bar', 'address', 'address', 'address', 'street', '[street]', 'data[foo].bar[street]'],
            [self::LEVEL_2, '[foo].bar', 'address', 'address', 'address', 'street', '[street]', 'data[foo].bar[street].prop'],
            [self::LEVEL_0, '[foo].bar', 'address', 'address', 'address', 'street', '[street]', 'data[foo][bar].street'],
            [self::LEVEL_0, '[foo].bar', 'address', 'address', 'address', 'street', '[street]', 'data[foo][bar].street.prop'],
            [self::LEVEL_0, '[foo].bar', 'address', 'address', 'address', 'street', '[street]', 'data[foo][bar][street]'],
            [self::LEVEL_0, '[foo].bar', 'address', 'address', 'address', 'street', '[street]', 'data[foo][bar][street].prop'],

            [self::LEVEL_0, '[foo][bar]', 'address', 'address', 'address', 'street', 'street', 'data.foo.bar.street'],
            [self::LEVEL_0, '[foo][bar]', 'address', 'address', 'address', 'street', 'street', 'data.foo.bar.street.prop'],
            [self::LEVEL_0, '[foo][bar]', 'address', 'address', 'address', 'street', 'street', 'data.foo.bar[street]'],
            [self::LEVEL_0, '[foo][bar]', 'address', 'address', 'address', 'street', 'street', 'data.foo.bar[street].prop'],
            [self::LEVEL_0, '[foo][bar]', 'address', 'address', 'address', 'street', 'street', 'data.foo[bar].street'],
            [self::LEVEL_0, '[foo][bar]', 'address', 'address', 'address', 'street', 'street', 'data.foo[bar].street.prop'],
            [self::LEVEL_0, '[foo][bar]', 'address', 'address', 'address', 'street', 'street', 'data.foo[bar][street]'],
            [self::LEVEL_0, '[foo][bar]', 'address', 'address', 'address', 'street', 'street', 'data.foo[bar][street].prop'],
            [self::LEVEL_0, '[foo][bar]', 'address', 'address', 'address', 'street', 'street', 'data[foo].bar.street'],
            [self::LEVEL_0, '[foo][bar]', 'address', 'address', 'address', 'street', 'street', 'data[foo].bar.street.prop'],
            [self::LEVEL_0, '[foo][bar]', 'address', 'address', 'address', 'street', 'street', 'data[foo].bar[street]'],
            [self::LEVEL_0, '[foo][bar]', 'address', 'address', 'address', 'street', 'street', 'data[foo].bar[street].prop'],
            [self::LEVEL_2, '[foo][bar]', 'address', 'address', 'address', 'street', 'street', 'data[foo][bar].street'],
            [self::LEVEL_2, '[foo][bar]', 'address', 'address', 'address', 'street', 'street', 'data[foo][bar].street.prop'],
            [self::LEVEL_1, '[foo][bar]', 'address', 'address', 'address', 'street', 'street', 'data[foo][bar][street]'],
            [self::LEVEL_1, '[foo][bar]', 'address', 'address', 'address', 'street', 'street', 'data[foo][bar][street].prop'],

            [self::LEVEL_0, '[foo][bar]', 'address', 'address', 'address', 'street', '[street]', 'data.foo.bar.street'],
            [self::LEVEL_0, '[foo][bar]', 'address', 'address', 'address', 'street', '[street]', 'data.foo.bar.street.prop'],
            [self::LEVEL_0, '[foo][bar]', 'address', 'address', 'address', 'street', '[street]', 'data.foo.bar[street]'],
            [self::LEVEL_0, '[foo][bar]', 'address', 'address', 'address', 'street', '[street]', 'data.foo.bar[street].prop'],
            [self::LEVEL_0, '[foo][bar]', 'address', 'address', 'address', 'street', '[street]', 'data.foo[bar].street'],
            [self::LEVEL_0, '[foo][bar]', 'address', 'address', 'address', 'street', '[street]', 'data.foo[bar].street.prop'],
            [self::LEVEL_0, '[foo][bar]', 'address', 'address', 'address', 'street', '[street]', 'data.foo[bar][street]'],
            [self::LEVEL_0, '[foo][bar]', 'address', 'address', 'address', 'street', '[street]', 'data.foo[bar][street].prop'],
            [self::LEVEL_0, '[foo][bar]', 'address', 'address', 'address', 'street', '[street]', 'data[foo].bar.street'],
            [self::LEVEL_0, '[foo][bar]', 'address', 'address', 'address', 'street', '[street]', 'data[foo].bar.street.prop'],
            [self::LEVEL_0, '[foo][bar]', 'address', 'address', 'address', 'street', '[street]', 'data[foo].bar[street]'],
            [self::LEVEL_0, '[foo][bar]', 'address', 'address', 'address', 'street', '[street]', 'data[foo].bar[street].prop'],
            [self::LEVEL_1, '[foo][bar]', 'address', 'address', 'address', 'street', '[street]', 'data[foo][bar].street'],
            [self::LEVEL_1, '[foo][bar]', 'address', 'address', 'address', 'street', '[street]', 'data[foo][bar].street.prop'],
            [self::LEVEL_2, '[foo][bar]', 'address', 'address', 'address', 'street', '[street]', 'data[foo][bar][street]'],
            [self::LEVEL_2, '[foo][bar]', 'address', 'address', 'address', 'street', '[street]', 'data[foo][bar][street].prop'],

            [self::LEVEL_2, 'foo', 'address.street', 'address', 'address', 'street', 'street', 'data.foo'],
            [self::LEVEL_2, 'foo', 'address.street', 'address', 'address', 'street', 'street', 'data.foo.prop'],
            [self::LEVEL_2, '[foo]', 'address.street', 'address', 'address', 'street', 'street', 'data[foo]'],
            [self::LEVEL_2, '[foo]', 'address.street', 'address', 'address', 'street', 'street', 'data[foo].prop'],

            [self::LEVEL_2, 'foo', 'address.street', 'address', 'address', 'street', '[street]', 'data.foo'],
            [self::LEVEL_2, 'foo', 'address.street', 'address', 'address', 'street', '[street]', 'data.foo.prop'],
            [self::LEVEL_2, '[foo]', 'address.street', 'address', 'address', 'street', '[street]', 'data[foo]'],
            [self::LEVEL_2, '[foo]', 'address.street', 'address', 'address', 'street', '[street]', 'data[foo].prop'],

            [self::LEVEL_2, 'foo', 'address.street', 'address', '[address]', 'street', 'street', 'data.foo'],
            [self::LEVEL_2, 'foo', 'address.street', 'address', '[address]', 'street', 'street', 'data.foo.prop'],
            [self::LEVEL_2, '[foo]', 'address.street', 'address', '[address]', 'street', 'street', 'data[foo]'],
            [self::LEVEL_2, '[foo]', 'address.street', 'address', '[address]', 'street', 'street', 'data[foo].prop'],

            [self::LEVEL_2, 'foo.bar', 'address.street', 'address', 'address', 'street', 'street', 'data.foo.bar'],
            [self::LEVEL_2, 'foo.bar', 'address.street', 'address', 'address', 'street', 'street', 'data.foo.bar.prop'],
            [self::LEVEL_2, 'foo[bar]', 'address.street', 'address', 'address', 'street', 'street', 'data.foo[bar]'],
            [self::LEVEL_2, 'foo[bar]', 'address.street', 'address', 'address', 'street', 'street', 'data.foo[bar].prop'],
            [self::LEVEL_2, '[foo].bar', 'address.street', 'address', 'address', 'street', 'street', 'data[foo].bar'],
            [self::LEVEL_2, '[foo].bar', 'address.street', 'address', 'address', 'street', 'street', 'data[foo].bar.prop'],
            [self::LEVEL_2, '[foo][bar]', 'address.street', 'address', 'address', 'street', 'street', 'data[foo][bar]'],
            [self::LEVEL_2, '[foo][bar]', 'address.street', 'address', 'address', 'street', 'street', 'data[foo][bar].prop'],

            [self::LEVEL_2, 'foo.bar', 'address.street', 'address', 'address', 'street', '[street]', 'data.foo.bar'],
            [self::LEVEL_2, 'foo.bar', 'address.street', 'address', 'address', 'street', '[street]', 'data.foo.bar.prop'],
            [self::LEVEL_2, 'foo[bar]', 'address.street', 'address', 'address', 'street', '[street]', 'data.foo[bar]'],
            [self::LEVEL_2, 'foo[bar]', 'address.street', 'address', 'address', 'street', '[street]', 'data.foo[bar].prop'],
            [self::LEVEL_2, '[foo].bar', 'address.street', 'address', 'address', 'street', '[street]', 'data[foo].bar'],
            [self::LEVEL_2, '[foo].bar', 'address.street', 'address', 'address', 'street', '[street]', 'data[foo].bar.prop'],
            [self::LEVEL_2, '[foo][bar]', 'address.street', 'address', 'address', 'street', '[street]', 'data[foo][bar]'],
            [self::LEVEL_2, '[foo][bar]', 'address.street', 'address', 'address', 'street', '[street]', 'data[foo][bar].prop'],

            [self::LEVEL_2, 'foo.bar', 'address.street', 'address', '[address]', 'street', 'street', 'data.foo.bar'],
            [self::LEVEL_2, 'foo.bar', 'address.street', 'address', '[address]', 'street', 'street', 'data.foo.bar.prop'],
            [self::LEVEL_2, 'foo[bar]', 'address.street', 'address', '[address]', 'street', 'street', 'data.foo[bar]'],
            [self::LEVEL_2, 'foo[bar]', 'address.street', 'address', '[address]', 'street', 'street', 'data.foo[bar].prop'],
            [self::LEVEL_2, '[foo].bar', 'address.street', 'address', '[address]', 'street', 'street', 'data[foo].bar'],
            [self::LEVEL_2, '[foo].bar', 'address.street', 'address', '[address]', 'street', 'street', 'data[foo].bar.prop'],
            [self::LEVEL_2, '[foo][bar]', 'address.street', 'address', '[address]', 'street', 'street', 'data[foo][bar]'],
            [self::LEVEL_2, '[foo][bar]', 'address.street', 'address', '[address]', 'street', 'street', 'data[foo][bar].prop'],

            // Edge cases
            [self::LEVEL_2, 'foo', 'address', 'address', '[address]', 'street', 'street', 'data.foo.street'],
            [self::LEVEL_2, 'foo', 'address', 'address', '[address]', 'street', 'street', 'data.foo.street.prop'],
            [self::LEVEL_1, 'foo', 'address', 'address', '[address]', 'street', 'street', 'data.foo[street]'],
            [self::LEVEL_1, 'foo', 'address', 'address', '[address]', 'street', 'street', 'data.foo[street].prop'],
            [self::LEVEL_0, 'foo', 'address', 'address', '[address]', 'street', 'street', 'data[foo].street'],
            [self::LEVEL_0, 'foo', 'address', 'address', '[address]', 'street', 'street', 'data[foo].street.prop'],
            [self::LEVEL_0, 'foo', 'address', 'address', '[address]', 'street', 'street', 'data[foo][street]'],
            [self::LEVEL_0, 'foo', 'address', 'address', '[address]', 'street', 'street', 'data[foo][street].prop'],

            [self::LEVEL_0, '[foo]', 'address', 'address', 'address', 'street', 'street', 'data.foo.street'],
            [self::LEVEL_0, '[foo]', 'address', 'address', 'address', 'street', 'street', 'data.foo.street.prop'],
            [self::LEVEL_0, '[foo]', 'address', 'address', 'address', 'street', 'street', 'data.foo[street]'],
            [self::LEVEL_0, '[foo]', 'address', 'address', 'address', 'street', 'street', 'data.foo[street].prop'],
            [self::LEVEL_2, '[foo]', 'address', 'address', 'address', 'street', 'street', 'data[foo].street'],
            [self::LEVEL_2, '[foo]', 'address', 'address', 'address', 'street', 'street', 'data[foo].street.prop'],
            [self::LEVEL_1, '[foo]', 'address', 'address', 'address', 'street', 'street', 'data[foo][street]'],
            [self::LEVEL_1, '[foo]', 'address', 'address', 'address', 'street', 'street', 'data[foo][street].prop'],
        ];
    }

    /**
     * @dataProvider provideCustomDataErrorTests
     */
    public function testCustomDataErrorMapping($target, $mapFrom, $mapTo, $childName, $childPath, $grandChildName, $grandChildPath, $violationPath)
    {
        $violation = $this->getConstraintViolation($violationPath);
        $parent = $this->getForm('parent', null, null, [$mapFrom => $mapTo]);
        $child = $this->getForm($childName, $childPath);
        $grandChild = $this->getForm($grandChildName, $grandChildPath);

        $parent->add($child);
        $child->add($grandChild);

        // Add a field mapped to the first element of $mapFrom
        // to try to distract the algorithm
        // Only add it if we expect the error to come up on a different
        // level than LEVEL_0, because in this case the error would
        // (correctly) be mapped to the distraction field
        if (self::LEVEL_0 !== $target) {
            $mapFromPath = new PropertyPath($mapFrom);
            $mapFromPrefix = $mapFromPath->isIndex(0)
                ? '['.$mapFromPath->getElement(0).']'
                : $mapFromPath->getElement(0);
            $distraction = $this->getForm('distraction', $mapFromPrefix);

            $parent->add($distraction);
        }

        $parent->submit([]);

        $this->mapper->mapViolation($violation, $parent);

        if (self::LEVEL_0 !== $target) {
            $this->assertCount(0, $distraction->getErrors(), 'distraction should not have an error, but has one');
        }

        if (self::LEVEL_0 === $target) {
            $this->assertEquals([$this->getFormError($violation, $parent)], iterator_to_array($parent->getErrors()), $parent->getName().' should have an error, but has none');
            $this->assertCount(0, $child->getErrors(), $childName.' should not have an error, but has one');
            $this->assertCount(0, $grandChild->getErrors(), $grandChildName.' should not have an error, but has one');
        } elseif (self::LEVEL_1 === $target) {
            $this->assertCount(0, $parent->getErrors(), $parent->getName().' should not have an error, but has one');
            $this->assertEquals([$this->getFormError($violation, $child)], iterator_to_array($child->getErrors()), $childName.' should have an error, but has none');
            $this->assertCount(0, $grandChild->getErrors(), $grandChildName.' should not have an error, but has one');
        } else {
            $this->assertCount(0, $parent->getErrors(), $parent->getName().' should not have an error, but has one');
            $this->assertCount(0, $child->getErrors(), $childName.' should not have an error, but has one');
            $this->assertEquals([$this->getFormError($violation, $grandChild)], iterator_to_array($grandChild->getErrors()), $grandChildName.' should have an error, but has none');
        }
    }

    public static function provideCustomFormErrorTests()
    {
        // This case is different than the data errors, because here the
        // left side of the mapping refers to the property path of the actual
        // children. In other words, a child error only works if
        // 1) the error actually maps to an existing child and
        // 2) the property path of that child (relative to the form providing
        //    the mapping) matches the left side of the mapping
        return [
            // mapping target, map from, map to, child name, its property path, grand child name, its property path, violation path
            [self::LEVEL_1, 'foo', 'address', 'foo', 'foo', 'address', 'address', 'street', 'street', 'children[foo].children[street].data'],
            [self::LEVEL_1, 'foo', 'address', 'foo', 'foo', 'address', 'address', 'street', 'street', 'children[foo].children[street].data.prop'],
            [self::LEVEL_2, 'foo', 'address', 'foo', 'foo', 'address', 'address', 'street', 'street', 'children[foo].data.street'],
            [self::LEVEL_2, 'foo', 'address', 'foo', 'foo', 'address', 'address', 'street', 'street', 'children[foo].data.street.prop'],
            [self::LEVEL_1, 'foo', 'address', 'foo', 'foo', 'address', 'address', 'street', 'street', 'children[foo].data[street]'],
            [self::LEVEL_1, 'foo', 'address', 'foo', 'foo', 'address', 'address', 'street', 'street', 'children[foo].data[street].prop'],

            [self::LEVEL_2, 'foo', 'address', 'foo', 'foo', 'address', 'address', 'street', 'street', 'children[address].children[street].data'],
            [self::LEVEL_2, 'foo', 'address', 'foo', 'foo', 'address', 'address', 'street', 'street', 'children[address].children[street].data.prop'],
            [self::LEVEL_2, 'foo', 'address', 'foo', 'foo', 'address', 'address', 'street', 'street', 'children[address].data.street'],
            [self::LEVEL_2, 'foo', 'address', 'foo', 'foo', 'address', 'address', 'street', 'street', 'children[address].data.street.prop'],
            [self::LEVEL_1, 'foo', 'address', 'foo', 'foo', 'address', 'address', 'street', 'street', 'children[address].data[street]'],
            [self::LEVEL_1, 'foo', 'address', 'foo', 'foo', 'address', 'address', 'street', 'street', 'children[address].data[street].prop'],

            // Property path of the erroneous field and mapping must match exactly
            [self::LEVEL_1B, 'foo', 'address', 'foo', '[foo]', 'address', 'address', 'street', 'street', 'children[foo].children[street].data'],
            [self::LEVEL_1B, 'foo', 'address', 'foo', '[foo]', 'address', 'address', 'street', 'street', 'children[foo].children[street].data.prop'],
            [self::LEVEL_1B, 'foo', 'address', 'foo', '[foo]', 'address', 'address', 'street', 'street', 'children[foo].data.street'],
            [self::LEVEL_1B, 'foo', 'address', 'foo', '[foo]', 'address', 'address', 'street', 'street', 'children[foo].data.street.prop'],
            [self::LEVEL_1B, 'foo', 'address', 'foo', '[foo]', 'address', 'address', 'street', 'street', 'children[foo].data[street]'],
            [self::LEVEL_1B, 'foo', 'address', 'foo', '[foo]', 'address', 'address', 'street', 'street', 'children[foo].data[street].prop'],

            [self::LEVEL_1B, '[foo]', 'address', 'foo', 'foo', 'address', 'address', 'street', 'street', 'children[foo].children[street].data'],
            [self::LEVEL_1B, '[foo]', 'address', 'foo', 'foo', 'address', 'address', 'street', 'street', 'children[foo].children[street].data.prop'],
            [self::LEVEL_1B, '[foo]', 'address', 'foo', 'foo', 'address', 'address', 'street', 'street', 'children[foo].data.street'],
            [self::LEVEL_1B, '[foo]', 'address', 'foo', 'foo', 'address', 'address', 'street', 'street', 'children[foo].data.street.prop'],
            [self::LEVEL_1B, '[foo]', 'address', 'foo', 'foo', 'address', 'address', 'street', 'street', 'children[foo].data[street]'],
            [self::LEVEL_1B, '[foo]', 'address', 'foo', 'foo', 'address', 'address', 'street', 'street', 'children[foo].data[street].prop'],

            [self::LEVEL_1, '[foo]', 'address', 'foo', '[foo]', 'address', 'address', 'street', 'street', 'children[foo].children[street].data'],
            [self::LEVEL_1, '[foo]', 'address', 'foo', '[foo]', 'address', 'address', 'street', 'street', 'children[foo].children[street].data.prop'],
            [self::LEVEL_2, '[foo]', 'address', 'foo', '[foo]', 'address', 'address', 'street', 'street', 'children[foo].data.street'],
            [self::LEVEL_2, '[foo]', 'address', 'foo', '[foo]', 'address', 'address', 'street', 'street', 'children[foo].data.street.prop'],
            [self::LEVEL_1, '[foo]', 'address', 'foo', '[foo]', 'address', 'address', 'street', 'street', 'children[foo].data[street]'],
            [self::LEVEL_1, '[foo]', 'address', 'foo', '[foo]', 'address', 'address', 'street', 'street', 'children[foo].data[street].prop'],

            [self::LEVEL_2, 'foo', 'address', 'foo', 'foo', 'address', 'address', 'street', '[street]', 'children[foo].children[street].data'],
            [self::LEVEL_2, 'foo', 'address', 'foo', 'foo', 'address', 'address', 'street', '[street]', 'children[foo].children[street].data.prop'],
            [self::LEVEL_1, 'foo', 'address', 'foo', 'foo', 'address', 'address', 'street', '[street]', 'children[foo].data.street'],
            [self::LEVEL_1, 'foo', 'address', 'foo', 'foo', 'address', 'address', 'street', '[street]', 'children[foo].data.street.prop'],
            [self::LEVEL_2, 'foo', 'address', 'foo', 'foo', 'address', 'address', 'street', '[street]', 'children[foo].data[street]'],
            [self::LEVEL_2, 'foo', 'address', 'foo', 'foo', 'address', 'address', 'street', '[street]', 'children[foo].data[street].prop'],

            [self::LEVEL_2, 'foo', 'address', 'foo', 'foo', 'address', 'address', 'street', '[street]', 'children[address].children[street].data'],
            [self::LEVEL_2, 'foo', 'address', 'foo', 'foo', 'address', 'address', 'street', '[street]', 'children[address].children[street].data.prop'],
            [self::LEVEL_1, 'foo', 'address', 'foo', 'foo', 'address', 'address', 'street', '[street]', 'children[address].data.street'],
            [self::LEVEL_1, 'foo', 'address', 'foo', 'foo', 'address', 'address', 'street', '[street]', 'children[address].data.street.prop'],
            [self::LEVEL_2, 'foo', 'address', 'foo', 'foo', 'address', 'address', 'street', '[street]', 'children[address].data[street]'],
            [self::LEVEL_2, 'foo', 'address', 'foo', 'foo', 'address', 'address', 'street', '[street]', 'children[address].data[street].prop'],

            [self::LEVEL_1, 'foo', 'address', 'foo', 'foo', 'address', '[address]', 'street', 'street', 'children[foo].children[street].data'],
            [self::LEVEL_1, 'foo', 'address', 'foo', 'foo', 'address', '[address]', 'street', 'street', 'children[foo].children[street].data.prop'],
            [self::LEVEL_2, 'foo', 'address', 'foo', 'foo', 'address', '[address]', 'street', 'street', 'children[foo].data.street'],
            [self::LEVEL_2, 'foo', 'address', 'foo', 'foo', 'address', '[address]', 'street', 'street', 'children[foo].data.street.prop'],
            [self::LEVEL_1, 'foo', 'address', 'foo', 'foo', 'address', '[address]', 'street', 'street', 'children[foo].data[street]'],
            [self::LEVEL_1, 'foo', 'address', 'foo', 'foo', 'address', '[address]', 'street', 'street', 'children[foo].data[street].prop'],

            [self::LEVEL_2, 'foo', 'address', 'foo', 'foo', 'address', '[address]', 'street', 'street', 'children[address].children[street].data'],
            [self::LEVEL_2, 'foo', 'address', 'foo', 'foo', 'address', '[address]', 'street', 'street', 'children[address].children[street].data.prop'],
            [self::LEVEL_2, 'foo', 'address', 'foo', 'foo', 'address', '[address]', 'street', 'street', 'children[address].data.street'],
            [self::LEVEL_2, 'foo', 'address', 'foo', 'foo', 'address', '[address]', 'street', 'street', 'children[address].data.street.prop'],
            [self::LEVEL_1, 'foo', 'address', 'foo', 'foo', 'address', '[address]', 'street', 'street', 'children[address].data[street]'],
            [self::LEVEL_1, 'foo', 'address', 'foo', 'foo', 'address', '[address]', 'street', 'street', 'children[address].data[street].prop'],

            [self::LEVEL_2, 'foo', 'address', 'foo', 'foo', 'address', '[address]', 'street', '[street]', 'children[foo].children[street].data'],
            [self::LEVEL_2, 'foo', 'address', 'foo', 'foo', 'address', '[address]', 'street', '[street]', 'children[foo].children[street].data.prop'],
            [self::LEVEL_1, 'foo', 'address', 'foo', 'foo', 'address', '[address]', 'street', '[street]', 'children[foo].data.street'],
            [self::LEVEL_1, 'foo', 'address', 'foo', 'foo', 'address', '[address]', 'street', '[street]', 'children[foo].data.street.prop'],
            [self::LEVEL_2, 'foo', 'address', 'foo', 'foo', 'address', '[address]', 'street', '[street]', 'children[foo].data[street]'],
            [self::LEVEL_2, 'foo', 'address', 'foo', 'foo', 'address', '[address]', 'street', '[street]', 'children[foo].data[street].prop'],

            [self::LEVEL_2, 'foo', 'address', 'foo', 'foo', 'address', '[address]', 'street', '[street]', 'children[address].children[street].data'],
            [self::LEVEL_2, 'foo', 'address', 'foo', 'foo', 'address', '[address]', 'street', '[street]', 'children[address].children[street].data.prop'],
            [self::LEVEL_1, 'foo', 'address', 'foo', 'foo', 'address', '[address]', 'street', '[street]', 'children[address].data.street'],
            [self::LEVEL_1, 'foo', 'address', 'foo', 'foo', 'address', '[address]', 'street', '[street]', 'children[address].data.street.prop'],
            [self::LEVEL_2, 'foo', 'address', 'foo', 'foo', 'address', '[address]', 'street', '[street]', 'children[address].data[street]'],
            [self::LEVEL_2, 'foo', 'address', 'foo', 'foo', 'address', '[address]', 'street', '[street]', 'children[address].data[street].prop'],

            // Map to a nested child
            [self::LEVEL_2, 'foo', 'address.street', 'foo', 'foo', 'address', 'address', 'street', 'street', 'children[foo]'],
            [self::LEVEL_2, 'foo', 'address.street', 'foo', 'foo', 'address', 'address', 'street', '[street]', 'children[foo]'],
            [self::LEVEL_2, 'foo', 'address.street', 'foo', 'foo', 'address', '[address]', 'street', 'street', 'children[foo]'],
            [self::LEVEL_2, 'foo', 'address.street', 'foo', 'foo', 'address', '[address]', 'street', '[street]', 'children[foo]'],

            // Map from a nested child
            [self::LEVEL_1B, 'address.street', 'foo', 'foo', 'foo', 'address', 'address', 'street', 'street', 'children[address].children[street]'],
            [self::LEVEL_1B, 'address.street', 'foo', 'foo', 'foo', 'address', 'address', 'street', 'street', 'children[address].data.street'],
            [self::LEVEL_1, 'address.street', 'foo', 'foo', 'foo', 'address', 'address', 'street', 'street', 'children[address].data[street]'],
            [self::LEVEL_2, 'address.street', 'foo', 'foo', 'foo', 'address', 'address', 'street', '[street]', 'children[address].children[street]'],
            [self::LEVEL_1B, 'address.street', 'foo', 'foo', 'foo', 'address', 'address', 'street', '[street]', 'children[address].data.street'],
            [self::LEVEL_2, 'address.street', 'foo', 'foo', 'foo', 'address', 'address', 'street', '[street]', 'children[address].data[street]'],
            [self::LEVEL_2, 'address.street', 'foo', 'foo', 'foo', 'address', '[address]', 'street', 'street', 'children[address].children[street]'],
            [self::LEVEL_2, 'address.street', 'foo', 'foo', 'foo', 'address', '[address]', 'street', 'street', 'children[address].data.street'],
            [self::LEVEL_1, 'address.street', 'foo', 'foo', 'foo', 'address', '[address]', 'street', 'street', 'children[address].data[street]'],
            [self::LEVEL_2, 'address.street', 'foo', 'foo', 'foo', 'address', '[address]', 'street', '[street]', 'children[address].children[street]'],
            [self::LEVEL_1, 'address.street', 'foo', 'foo', 'foo', 'address', '[address]', 'street', '[street]', 'children[address].data.street'],
            [self::LEVEL_2, 'address.street', 'foo', 'foo', 'foo', 'address', '[address]', 'street', '[street]', 'children[address].data[street]'],

            [self::LEVEL_2, 'address[street]', 'foo', 'foo', 'foo', 'address', 'address', 'street', 'street', 'children[address].children[street]'],
            [self::LEVEL_2, 'address[street]', 'foo', 'foo', 'foo', 'address', 'address', 'street', 'street', 'children[address].data.street'],
            [self::LEVEL_1B, 'address[street]', 'foo', 'foo', 'foo', 'address', 'address', 'street', 'street', 'children[address].data[street]'],
            [self::LEVEL_1B, 'address[street]', 'foo', 'foo', 'foo', 'address', 'address', 'street', '[street]', 'children[address].children[street]'],
            [self::LEVEL_1, 'address[street]', 'foo', 'foo', 'foo', 'address', 'address', 'street', '[street]', 'children[address].data.street'],
            [self::LEVEL_1B, 'address[street]', 'foo', 'foo', 'foo', 'address', 'address', 'street', '[street]', 'children[address].data[street]'],
            [self::LEVEL_2, 'address[street]', 'foo', 'foo', 'foo', 'address', '[address]', 'street', 'street', 'children[address].children[street]'],
            [self::LEVEL_2, 'address[street]', 'foo', 'foo', 'foo', 'address', '[address]', 'street', 'street', 'children[address].data.street'],
            [self::LEVEL_1, 'address[street]', 'foo', 'foo', 'foo', 'address', '[address]', 'street', 'street', 'children[address].data[street]'],
            [self::LEVEL_2, 'address[street]', 'foo', 'foo', 'foo', 'address', '[address]', 'street', '[street]', 'children[address].children[street]'],
            [self::LEVEL_1, 'address[street]', 'foo', 'foo', 'foo', 'address', '[address]', 'street', '[street]', 'children[address].data.street'],
            [self::LEVEL_2, 'address[street]', 'foo', 'foo', 'foo', 'address', '[address]', 'street', '[street]', 'children[address].data[street]'],

            [self::LEVEL_2, '[address].street', 'foo', 'foo', 'foo', 'address', 'address', 'street', 'street', 'children[address].children[street]'],
            [self::LEVEL_2, '[address].street', 'foo', 'foo', 'foo', 'address', 'address', 'street', 'street', 'children[address].data.street'],
            [self::LEVEL_1, '[address].street', 'foo', 'foo', 'foo', 'address', 'address', 'street', 'street', 'children[address].data[street]'],
            [self::LEVEL_2, '[address].street', 'foo', 'foo', 'foo', 'address', 'address', 'street', '[street]', 'children[address].children[street]'],
            [self::LEVEL_1, '[address].street', 'foo', 'foo', 'foo', 'address', 'address', 'street', '[street]', 'children[address].data.street'],
            [self::LEVEL_2, '[address].street', 'foo', 'foo', 'foo', 'address', 'address', 'street', '[street]', 'children[address].data[street]'],
            [self::LEVEL_1B, '[address].street', 'foo', 'foo', 'foo', 'address', '[address]', 'street', 'street', 'children[address].children[street]'],
            [self::LEVEL_1B, '[address].street', 'foo', 'foo', 'foo', 'address', '[address]', 'street', 'street', 'children[address].data.street'],
            [self::LEVEL_1, '[address].street', 'foo', 'foo', 'foo', 'address', '[address]', 'street', 'street', 'children[address].data[street]'],
            [self::LEVEL_2, '[address].street', 'foo', 'foo', 'foo', 'address', '[address]', 'street', '[street]', 'children[address].children[street]'],
            [self::LEVEL_1B, '[address].street', 'foo', 'foo', 'foo', 'address', '[address]', 'street', '[street]', 'children[address].data.street'],
            [self::LEVEL_2, '[address].street', 'foo', 'foo', 'foo', 'address', '[address]', 'street', '[street]', 'children[address].data[street]'],

            [self::LEVEL_2, '[address][street]', 'foo', 'foo', 'foo', 'address', 'address', 'street', 'street', 'children[address].children[street]'],
            [self::LEVEL_2, '[address][street]', 'foo', 'foo', 'foo', 'address', 'address', 'street', 'street', 'children[address].data.street'],
            [self::LEVEL_1, '[address][street]', 'foo', 'foo', 'foo', 'address', 'address', 'street', 'street', 'children[address].data[street]'],
            [self::LEVEL_2, '[address][street]', 'foo', 'foo', 'foo', 'address', 'address', 'street', '[street]', 'children[address].children[street]'],
            [self::LEVEL_1, '[address][street]', 'foo', 'foo', 'foo', 'address', 'address', 'street', '[street]', 'children[address].data.street'],
            [self::LEVEL_2, '[address][street]', 'foo', 'foo', 'foo', 'address', 'address', 'street', '[street]', 'children[address].data[street]'],
            [self::LEVEL_2, '[address][street]', 'foo', 'foo', 'foo', 'address', '[address]', 'street', 'street', 'children[address].children[street]'],
            [self::LEVEL_2, '[address][street]', 'foo', 'foo', 'foo', 'address', '[address]', 'street', 'street', 'children[address].data.street'],
            [self::LEVEL_1B, '[address][street]', 'foo', 'foo', 'foo', 'address', '[address]', 'street', 'street', 'children[address].data[street]'],
            [self::LEVEL_1B, '[address][street]', 'foo', 'foo', 'foo', 'address', '[address]', 'street', '[street]', 'children[address].children[street]'],
            [self::LEVEL_1, '[address][street]', 'foo', 'foo', 'foo', 'address', '[address]', 'street', '[street]', 'children[address].data.street'],
            [self::LEVEL_1B, '[address][street]', 'foo', 'foo', 'foo', 'address', '[address]', 'street', '[street]', 'children[address].data[street]'],
        ];
    }

    /**
     * @dataProvider provideCustomFormErrorTests
     */
    public function testCustomFormErrorMapping($target, $mapFrom, $mapTo, $errorName, $errorPath, $childName, $childPath, $grandChildName, $grandChildPath, $violationPath)
    {
        $violation = $this->getConstraintViolation($violationPath);
        $parent = $this->getForm('parent', null, null, [$mapFrom => $mapTo]);
        $child = $this->getForm($childName, $childPath);
        $grandChild = $this->getForm($grandChildName, $grandChildPath);
        $errorChild = $this->getForm($errorName, $errorPath);

        $parent->add($child);
        $parent->add($errorChild);
        $child->add($grandChild);

        $parent->submit([]);

        $this->mapper->mapViolation($violation, $parent);

        if (self::LEVEL_0 === $target) {
            $this->assertCount(0, $errorChild->getErrors(), $errorName.' should not have an error, but has one');
            $this->assertEquals([$this->getFormError($violation, $parent)], iterator_to_array($parent->getErrors()), $parent->getName().' should have an error, but has none');
            $this->assertCount(0, $child->getErrors(), $childName.' should not have an error, but has one');
            $this->assertCount(0, $grandChild->getErrors(), $grandChildName.' should not have an error, but has one');
        } elseif (self::LEVEL_1 === $target) {
            $this->assertCount(0, $errorChild->getErrors(), $errorName.' should not have an error, but has one');
            $this->assertCount(0, $parent->getErrors(), $parent->getName().' should not have an error, but has one');
            $this->assertEquals([$this->getFormError($violation, $child)], iterator_to_array($child->getErrors()), $childName.' should have an error, but has none');
            $this->assertCount(0, $grandChild->getErrors(), $grandChildName.' should not have an error, but has one');
        } elseif (self::LEVEL_1B === $target) {
            $this->assertEquals([$this->getFormError($violation, $errorChild)], iterator_to_array($errorChild->getErrors()), $errorName.' should have an error, but has none');
            $this->assertCount(0, $parent->getErrors(), $parent->getName().' should not have an error, but has one');
            $this->assertCount(0, $child->getErrors(), $childName.' should not have an error, but has one');
            $this->assertCount(0, $grandChild->getErrors(), $grandChildName.' should not have an error, but has one');
        } else {
            $this->assertCount(0, $errorChild->getErrors(), $errorName.' should not have an error, but has one');
            $this->assertCount(0, $parent->getErrors(), $parent->getName().' should not have an error, but has one');
            $this->assertCount(0, $child->getErrors(), $childName.' should not have an error, but has one');
            $this->assertEquals([$this->getFormError($violation, $grandChild)], iterator_to_array($grandChild->getErrors()), $grandChildName.' should have an error, but has none');
        }
    }

    public static function provideErrorTestsForFormInheritingParentData()
    {
        return [
            // mapping target, child name, its property path, grand child name, its property path, violation path
            [self::LEVEL_2, 'address', 'address', 'street', 'street', 'children[address].children[street].data'],
            [self::LEVEL_2, 'address', 'address', 'street', 'street', 'children[address].children[street].data.prop'],
            [self::LEVEL_2, 'address', 'address', 'street', 'street', 'children[address].data.street'],
            [self::LEVEL_2, 'address', 'address', 'street', 'street', 'children[address].data.street.prop'],
            [self::LEVEL_1, 'address', 'address', 'street', 'street', 'children[address].data[street]'],
            [self::LEVEL_1, 'address', 'address', 'street', 'street', 'children[address].data[street].prop'],
            [self::LEVEL_2, 'address', 'address', 'street', 'street', 'data.street'],
            [self::LEVEL_2, 'address', 'address', 'street', 'street', 'data.street.prop'],
            [self::LEVEL_0, 'address', 'address', 'street', 'street', 'data[street]'],
            [self::LEVEL_0, 'address', 'address', 'street', 'street', 'data[street].prop'],
            [self::LEVEL_0, 'address', 'address', 'street', 'street', 'data.address.street'],
            [self::LEVEL_0, 'address', 'address', 'street', 'street', 'data.address.street.prop'],
            [self::LEVEL_0, 'address', 'address', 'street', 'street', 'data.address[street]'],
            [self::LEVEL_0, 'address', 'address', 'street', 'street', 'data.address[street].prop'],
            [self::LEVEL_0, 'address', 'address', 'street', 'street', 'data[address].street'],
            [self::LEVEL_0, 'address', 'address', 'street', 'street', 'data[address].street.prop'],
            [self::LEVEL_0, 'address', 'address', 'street', 'street', 'data[address][street]'],
            [self::LEVEL_0, 'address', 'address', 'street', 'street', 'data[address][street].prop'],
        ];
    }

    /**
     * @dataProvider provideErrorTestsForFormInheritingParentData
     */
    public function testErrorMappingForFormInheritingParentData($target, $childName, $childPath, $grandChildName, $grandChildPath, $violationPath)
    {
        $violation = $this->getConstraintViolation($violationPath);
        $parent = $this->getForm('parent');
        $child = $this->getForm($childName, $childPath, null, [], true);
        $grandChild = $this->getForm($grandChildName, $grandChildPath);

        $parent->add($child);
        $child->add($grandChild);

        $parent->submit([]);

        $this->mapper->mapViolation($violation, $parent);

        if (self::LEVEL_0 === $target) {
            $this->assertEquals([$this->getFormError($violation, $parent)], iterator_to_array($parent->getErrors()), $parent->getName().' should have an error, but has none');
            $this->assertCount(0, $child->getErrors(), $childName.' should not have an error, but has one');
            $this->assertCount(0, $grandChild->getErrors(), $grandChildName.' should not have an error, but has one');
        } elseif (self::LEVEL_1 === $target) {
            $this->assertCount(0, $parent->getErrors(), $parent->getName().' should not have an error, but has one');
            $this->assertEquals([$this->getFormError($violation, $child)], iterator_to_array($child->getErrors()), $childName.' should have an error, but has none');
            $this->assertCount(0, $grandChild->getErrors(), $grandChildName.' should not have an error, but has one');
        } else {
            $this->assertCount(0, $parent->getErrors(), $parent->getName().' should not have an error, but has one');
            $this->assertCount(0, $child->getErrors(), $childName.' should not have an error, but has one');
            $this->assertEquals([$this->getFormError($violation, $grandChild)], iterator_to_array($grandChild->getErrors()), $grandChildName.' should have an error, but has none');
        }
    }

    public function testBacktrackIfSeveralSubFormsWithSamePropertyPath()
    {
        $parent = $this->getForm('parent');
        $child1 = $this->getForm('subform1', 'address');
        $child2 = $this->getForm('subform2', 'address');
        $child3 = $this->getForm('subform3', null, null, [], true);
        $child4 = $this->getForm('subform4', null, null, [], true);
        $grandChild1 = $this->getForm('street');
        $grandChild2 = $this->getForm('street', '[sub_address1_street]');
        $grandChild3 = $this->getForm('street', '[sub_address2_street]');

        $parent->add($child1);
        $parent->add($child2);
        $parent->add($child3);
        $parent->add($child4);
        $child2->add($grandChild1);
        $child3->add($grandChild2);
        $child4->add($grandChild3);

        $parent->submit([]);

        $violation1 = $this->getConstraintViolation('data.address[street]');
        $violation2 = $this->getConstraintViolation('data[sub_address1_street]');
        $violation3 = $this->getConstraintViolation('data[sub_address2_street]');
        $this->mapper->mapViolation($violation1, $parent);
        $this->mapper->mapViolation($violation2, $parent);
        $this->mapper->mapViolation($violation3, $parent);

        $this->assertCount(0, $parent->getErrors(), $parent->getName().' should not have an error, but has one');
        $this->assertCount(0, $child1->getErrors(), $child1->getName().' should not have an error, but has one');
        $this->assertCount(0, $child2->getErrors(), $child2->getName().' should not have an error, but has one');
        $this->assertCount(0, $child3->getErrors(), $child3->getName().' should not have an error, but has one');
        $this->assertCount(0, $child4->getErrors(), $child4->getName().' should not have an error, but has one');
        $this->assertEquals([$this->getFormError($violation1, $grandChild1)], iterator_to_array($grandChild1->getErrors()), $grandChild1->getName().' should have an error, but has none');
        $this->assertEquals([$this->getFormError($violation2, $grandChild2)], iterator_to_array($grandChild2->getErrors()), $grandChild2->getName().' should have an error, but has none');
        $this->assertEquals([$this->getFormError($violation3, $grandChild3)], iterator_to_array($grandChild3->getErrors()), $grandChild3->getName().' should have an error, but has none');
    }

    public function testMessageWithLabel1()
    {
        $this->mapper = new ViolationMapper(new FormRenderer(new DummyFormRendererEngine()), new FixedTranslator(['Name' => 'Custom Name']));

        $parent = $this->getForm('parent');
        $child = $this->getForm('name', 'name');
        $parent->add($child);

        $parent->submit([]);

        $violation = new ConstraintViolation('Message {{ label }}', null, [], null, 'data.name', null);
        $this->mapper->mapViolation($violation, $parent);

        $this->assertCount(1, $child->getErrors(), $child->getName().' should have an error, but has none');

        $errors = iterator_to_array($child->getErrors());
        if (isset($errors[0])) {
            /** @var FormError $error */
            $error = $errors[0];
            $this->assertSame('Message Custom Name', $error->getMessage());
        }
    }

    public function testMessageWithLabel2()
    {
        $this->mapper = new ViolationMapper(null, new FixedTranslator(['options_label' => 'Translated Label']));

        $parent = $this->getForm('parent');

        $config = new FormConfigBuilder('name', null, $this->dispatcher, [
            'error_mapping' => [],
            'label' => 'options_label',
        ]);
        $config->setMapped(true);
        $config->setInheritData(false);
        $config->setPropertyPath('name');
        $config->setCompound(true);
        $config->setDataMapper(new DataMapper());

        $child = new Form($config);
        $parent->add($child);

        $parent->submit([]);

        $violation = new ConstraintViolation('Message {{ label }}', null, [], null, 'data.name', null);
        $this->mapper->mapViolation($violation, $parent);

        $this->assertCount(1, $child->getErrors(), $child->getName().' should have an error, but has none');

        $errors = iterator_to_array($child->getErrors());
        if (isset($errors[0])) {
            /** @var FormError $error */
            $error = $errors[0];
            $this->assertSame('Message Translated Label', $error->getMessage());
        }
    }

    public function testMessageWithLabelFormat1()
    {
        $this->mapper = new ViolationMapper(null, new FixedTranslator(['form.custom' => 'Translated 1st Custom Label']));

        $parent = $this->getForm('parent');

        $config = new FormConfigBuilder('custom', null, $this->dispatcher, [
            'error_mapping' => [],
            'label_format' => 'form.%name%',
        ]);
        $config->setMapped(true);
        $config->setInheritData(false);
        $config->setPropertyPath('custom');
        $config->setCompound(true);
        $config->setDataMapper(new DataMapper());

        $child = new Form($config);
        $parent->add($child);

        $parent->submit([]);

        $violation = new ConstraintViolation('Message {{ label }}', null, [], null, 'data.custom', null);
        $this->mapper->mapViolation($violation, $parent);

        $this->assertCount(1, $child->getErrors(), $child->getName().' should have an error, but has none');

        $errors = iterator_to_array($child->getErrors());
        if (isset($errors[0])) {
            /** @var FormError $error */
            $error = $errors[0];
            $this->assertSame('Message Translated 1st Custom Label', $error->getMessage());
        }
    }

    public function testMessageWithLabelFormat2()
    {
        $this->mapper = new ViolationMapper(null, new FixedTranslator(['form_custom-id' => 'Translated 2nd Custom Label']));

        $parent = $this->getForm('parent');

        $config = new FormConfigBuilder('custom-id', null, $this->dispatcher, [
            'error_mapping' => [],
            'label_format' => 'form_%id%',
        ]);
        $config->setMapped(true);
        $config->setInheritData(false);
        $config->setPropertyPath('custom-id');
        $config->setCompound(true);
        $config->setDataMapper(new DataMapper());

        $child = new Form($config);
        $parent->add($child);

        $parent->submit([]);

        $violation = new ConstraintViolation('Message {{ label }}', null, [], null, 'data.custom-id', null);
        $this->mapper->mapViolation($violation, $parent);

        $this->assertCount(1, $child->getErrors(), $child->getName().' should have an error, but has none');

        $errors = iterator_to_array($child->getErrors());
        if (isset($errors[0])) {
            /** @var FormError $error */
            $error = $errors[0];
            $this->assertSame('Message Translated 2nd Custom Label', $error->getMessage());
        }
    }

    public function testLabelFormatDefinedByParentType()
    {
        $form = $this->getForm('', null, null, [], false, true, [
            'label_format' => 'form.%name%',
        ]);
        $child = $this->getForm('foo', 'foo');
        $form->add($child);

        $violation = new ConstraintViolation('Message "{{ label }}"', null, [], null, 'data.foo', null);
        $this->mapper->mapViolation($violation, $form);

        $errors = iterator_to_array($child->getErrors());

        $this->assertCount(1, $errors, $child->getName().' should have an error, but has none');
        $this->assertSame('Message "form.foo"', $errors[0]->getMessage());
    }

    public function testLabelPlaceholderTranslatedWithTranslationDomainDefinedByParentType()
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects($this->any())
            ->method('trans')
            ->with('foo', [], 'domain')
            ->willReturn('translated foo label')
        ;
        $this->mapper = new ViolationMapper(null, $translator);

        $form = $this->getForm('', null, null, [], false, true, [
            'translation_domain' => 'domain',
        ]);
        $child = $this->getForm('foo', 'foo', null, [], false, true, [
            'label' => 'foo',
        ]);
        $form->add($child);

        $violation = new ConstraintViolation('Message "{{ label }}"', null, [], null, 'data.foo', null);
        $this->mapper->mapViolation($violation, $form);

        $errors = iterator_to_array($child->getErrors());

        $this->assertCount(1, $errors, $child->getName().' should have an error, but has none');
        $this->assertSame('Message "translated foo label"', $errors[0]->getMessage());
    }

    public function testLabelPlaceholderTranslatedWithTranslationParametersMergedFromParentForm()
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects($this->any())
            ->method('trans')
            ->with('foo', [
                '{{ param_defined_in_parent }}' => 'param defined in parent value',
                '{{ param_defined_in_child }}' => 'param defined in child value',
                '{{ param_defined_in_parent_overridden_in_child }}' => 'param defined in parent overridden in child child value',
            ])
            ->willReturn('translated foo label')
        ;
        $this->mapper = new ViolationMapper(null, $translator);

        $form = $this->getForm('', null, null, [], false, true, [
            'label_translation_parameters' => [
                '{{ param_defined_in_parent }}' => 'param defined in parent value',
                '{{ param_defined_in_parent_overridden_in_child }}' => 'param defined in parent overridden in child parent value',
            ],
        ]);
        $child = $this->getForm('foo', 'foo', null, [], false, true, [
            'label' => 'foo',
            'label_translation_parameters' => [
                '{{ param_defined_in_child }}' => 'param defined in child value',
                '{{ param_defined_in_parent_overridden_in_child }}' => 'param defined in parent overridden in child child value',
            ],
        ]);
        $form->add($child);

        $violation = new ConstraintViolation('Message "{{ label }}"', null, [], null, 'data.foo', null);
        $this->mapper->mapViolation($violation, $form);

        $errors = iterator_to_array($child->getErrors());

        $this->assertCount(1, $errors, $child->getName().' should have an error, but has none');
        $this->assertSame('Message "translated foo label"', $errors[0]->getMessage());
    }

    public function testTranslatorNotCalledWithoutLabel()
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects($this->never())->method('trans');
        $this->mapper = new ViolationMapper(new FormRenderer(new DummyFormRendererEngine()), $translator);

        $parent = $this->getForm('parent');
        $child = $this->getForm('name', 'name');
        $parent->add($child);

        $parent->submit([]);

        $violation = new ConstraintViolation('Message without label', null, [], null, 'data.name', null);
        $this->mapper->mapViolation($violation, $parent);
    }

    public function testFileUploadErrorIsNotRemovedIfNoFileSizeConstraintViolationWasRaised()
    {
        $form = $this->getForm('form');
        $form->addError(new FileUploadError(
            'The file is too large. Allowed maximum size is 2 MB.',
            'The file is too large. Allowed maximum size is {{ limit }} {{ suffix }}.',
            [
                '{{ limit }}' => '2',
                '{{ suffix }}' => 'MB',
            ]
        ));

        $this->mapper->mapViolation($this->getConstraintViolation('data'), $form);

        $this->assertCount(2, $form->getErrors());
    }

    public function testFileUploadErrorIsRemovedIfFileSizeConstraintViolationWasRaised()
    {
        $form = $this->getForm('form');
        $form->addError(new FileUploadError(
            'The file is too large. Allowed maximum size is 2 MB.',
            'The file is too large. Allowed maximum size is {{ limit }} {{ suffix }}.',
            [
                '{{ limit }}' => '2',
                '{{ suffix }}' => 'MB',
            ]
        ));

        $violation = new ConstraintViolation(
            'The file is too large (3 MB). Allowed maximum size is 2 MB.',
            'The file is too large ({{ size }} {{ suffix }}). Allowed maximum size is {{ limit }} {{ suffix }}.',
            [
                '{{ limit }}' => '2',
                '{{ size }}' => '3',
                '{{ suffix }}' => 'MB',
            ],
            '',
            'data',
            null,
            null,
            (string) \UPLOAD_ERR_INI_SIZE,
            new File()
        );
        $this->mapper->mapViolation($this->getConstraintViolation('data'), $form);
        $this->mapper->mapViolation($violation, $form);

        $this->assertCount(2, $form->getErrors());
    }

    public function testFileUploadErrorIsRemovedIfFileSizeConstraintViolationWasRaisedOnFieldWithErrorBubbling()
    {
        $parent = $this->getForm('parent');
        $child = $this->getForm('child', 'file', null, [], false, true, [
            'error_bubbling' => true,
        ]);
        $parent->add($child);
        $child->addError(new FileUploadError(
            'The file is too large. Allowed maximum size is 2 MB.',
            'The file is too large. Allowed maximum size is {{ limit }} {{ suffix }}.',
            [
                '{{ limit }}' => '2',
                '{{ suffix }}' => 'MB',
            ]
        ));

        $violation = new ConstraintViolation(
            'The file is too large (3 MB). Allowed maximum size is 2 MB.',
            'The file is too large ({{ size }} {{ suffix }}). Allowed maximum size is {{ limit }} {{ suffix }}.',
            [
                '{{ limit }}' => '2',
                '{{ size }}' => '3',
                '{{ suffix }}' => 'MB',
            ],
            null,
            'data.file',
            null,
            null,
            (string) \UPLOAD_ERR_INI_SIZE,
            new File()
        );
        $this->mapper->mapViolation($this->getConstraintViolation('data'), $parent);
        $this->mapper->mapViolation($this->getConstraintViolation('data.file'), $parent);
        $this->mapper->mapViolation($violation, $parent);

        $this->assertCount(3, $parent->getErrors());
        $this->assertCount(0, $child->getErrors());
    }
}
