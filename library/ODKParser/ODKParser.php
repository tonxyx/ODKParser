<?php

namespace ODKParser;

use Administration\Entity\Form;
use Administration\Entity\FormElement;
use Administration\Entity\FormFieldset;

use Zend\Di\ServiceLocator;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\Config\Reader\Xml;

use Doctrine\ORM\EntityManager;
use Zend\ServiceManager\ServiceManager;

class ODKParser extends AbstractActionController {

    protected $em;
    protected $sm;

    public function __construct (ServiceManager $serviceManager) {
       $this->sm = $serviceManager;
    }

    public function setEntityManager (EntityManager $em) {
        $this->em = $em;
    }

    public function getEntityManager () {
        if (null === $this->em) {
            $this->em = $this->sm->get('Doctrine\ORM\EntityManager');
        }
        return $this->em;
    }

    public function xmlToForm($file) {

        $reader = new Xml();
        $xml = $reader->fromFile($file);

        $form = $this->getEntityManager()->getRepository('Administration\Entity\Form')
            ->findOneBy(array('name' => $xml['h:head']["h:title"]));

        if (!$form) {
            $form = new Form();
            $form->setName($xml['h:head']["h:title"]);
            $form->setTitle($xml['h:head']["h:title"]);

            //todo get current session country
            $country = $this->getEntityManager()->getRepository('Administration\Entity\CodeCountries')
                ->findOneBy(array('id' => 2));
            $form->setCountry($country);

            $this->getEntityManager()->persist($form);
            $this->getEntityManager()->flush();
        }

        foreach ($xml['h:body']['group'] as $field) {

            if (isset($field['label']))
                $fieldset = $this->formFieldset($field, $xml, $form);

            if (isset($field['input']))
                $this->structFormElement($field, $fieldset, $xml, 'input');

            if (isset($field['upload']))
                $this->structFormElement($field, $fieldset, $xml, 'upload');

            if (isset($field['select1']))
                $this->structFormElement($field, $fieldset, $xml, 'select1');

            if (isset($field['select']))
                $this->structFormElement($field, $fieldset, $xml, 'select');

            if (isset($field['group']))
                $this->groupFormElement($field, $fieldset, $xml);

            if (isset($field['repeat']))
                $this->repeatFormElement($field, $fieldset, $xml);

        }

    }

    private function groupFormElement ($field, $fieldset = null, $xml) {

        foreach ($field['group'] as $formEl) {

            $formElement = new FormElement();
            $formElement->setParentType('group');
            $formElement->setFieldset($fieldset);

            preg_match("/('(.*?)')/", $formEl['label']['ref'], $optionMatch);

            foreach ($xml['h:head']['model']['itext']['translation']['text'] as $sub) {
                if ($sub['id'] === $optionMatch[2]) {
                    $formElement->setLabel($sub['value']);
                    break;
                }
            }

            $this->getEntityManager()->persist($formElement);
            $this->getEntityManager()->flush();

            if (isset($formEl['repeat'])) {
                $this->repeatFormElement($formEl['repeat'], $fieldset, $xml, $formElement->getId());
            }
        }

    }

    private function repeatFormElement ($formEl, $fieldset = null, $xml, $parentId = null) {

        $formElement = new FormElement();

        if (isset($parentId))
            $formElement->setParentId($parentId);

        $formElement->setParentType('repeat');
        $formElement->setFieldset($fieldset);

        $this->getEntityManager()->persist($formElement);
        $this->getEntityManager()->flush();

        unset($formEl['nodeset']);
        unset($formEl['appearance']);

        foreach ($formEl as $key => $element)
            $this->structFormElement($formEl, $fieldset, $xml, $key, $formElement->getId());

    }

    private function structFormElement ($field, $fieldset = null, $xml, $type, $parentId = null) {

        if (isset($field[$type][0])) {
            foreach ($field[$type] as $formEl) {

                $formElement = new FormElement();
                $formElement->setRef($formEl['ref']);

                if ($parentId)
                    $formElement->setParentId($parentId);

                $formElement->setParentType($type);
                $formElement->setFieldset($fieldset);

                if (isset($formEl['mediatype']))
                    $formElement->setMediaType(substr($formEl['mediatype'], 0, -2));

                foreach ($xml['h:head']['model']['itext']['translation']['text'] as $sub) {
                    if ($sub['id'] === ($formEl['ref'] . ':label')) {
                        $formElement->setLabel($sub['value']);
                        break;
                    }
                }

                foreach ($xml['h:head']['model']['bind'] as $bind) {
                    if ($bind['nodeset'] === $formEl['ref']) {
                        $formElement->setType($bind['type']);
                        if (isset($bind['required']))
                            $formElement->setRequired(substr($bind['required'], 0, -2));
                        break;
                    }
                }

                if (isset($formEl['item'])) {
                    $valueOptions = array();
                    foreach ($formEl['item'] as $option) {
                        preg_match("/('(.*?)')/", $option['label']['ref'], $optionMatch);
                        foreach ($xml['h:head']['model']['itext']['translation']['text'] as $sub) {
                            if ($sub['id'] === $optionMatch[2]) {
                                $valueOptions[$option['value']] = $sub['value'];
                                break;
                            }
                        }
                    }
                    $formElement->setValueOptions(json_encode($valueOptions));
                }

                $this->getEntityManager()->persist($formElement);
                $this->getEntityManager()->flush();
            }
        } else if (isset($field[$type]['ref'])){

            $formElement = new FormElement();
            $formElement->setRef($field[$type]['ref']);

            if ($parentId)
                $formElement->setParentId($parentId);

            $formElement->setParentType($type);
            $formElement->setFieldset($fieldset);

            if (isset($field[$type]['mediatype']))
                $formElement->setMediaType(substr($field[$type]['mediatype'], 0, -2));

            foreach ($xml['h:head']['model']['itext']['translation']['text'] as $sub) {
                if ($sub['id'] === ($field[$type]['ref'] . ':label')) {
                    $formElement->setLabel($sub['value']);
                    break;
                }
            }

            foreach ($xml['h:head']['model']['bind'] as $bind) {
                if ($bind['nodeset'] === $field[$type]['ref']) {
                    $formElement->setType($bind['type']);
                    if (isset($bind['required']))
                        $formElement->setRequired(substr($bind['required'], 0, -2));
                    break;
                }
            }

            if (isset($field[$type]['item'])) {
                $valueOptions = array();
                foreach ($field[$type]['item'] as $option) {
                    preg_match("/('(.*?)')/", $option['label']['ref'], $optionMatch);
                    foreach ($xml['h:head']['model']['itext']['translation']['text'] as $sub) {
                        if ($sub['id'] === $optionMatch[2]) {
                            $valueOptions[$option['value']] = $sub['value'];
                            break;
                        }
                    }
                }
                $formElement->setValueOptions(json_encode($valueOptions));
            }

            $this->getEntityManager()->persist($formElement);
            $this->getEntityManager()->flush();
        }

    }

    private function formFieldset ($field, $xml, $form) {

        preg_match("/('(.*?)')/", $field['label']['ref'], $fieldsetMatch);

        foreach ($xml['h:head']['model']['itext']['translation']['text'] as $sub) {
            if ($sub['id'] === $fieldsetMatch[2]) {
                $fieldset = $this->getEntityManager()->getRepository('Administration\Entity\FormFieldset')
                    ->findOneBy(array('form' => $form, 'name' => $sub['value']));

                if (!$fieldset) {
                    $fieldset = new FormFieldset();
                    $fieldset->setForm($form);
                    $fieldset->setName($sub['value']);
                    $this->getEntityManager()->persist($fieldset);
                    $this->getEntityManager()->flush();
                }
                return $fieldset;
            }
        }

    }
}
