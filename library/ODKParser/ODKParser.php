<?php
    
    namespace ODKParser;
    
    use Administration\Entity\Form;
    use Administration\Entity\FormElement;
    use Administration\Entity\FormFieldset;
    use Administration\Entity\Survey;
    
    use Zend\Di\ServiceLocator;
    use Zend\Mvc\Controller\AbstractActionController;
    use Zend\Config\Reader\Xml;
    use Zend\Code\Generator\ClassGenerator;
    use Zend\Code\Generator\MethodGenerator;
    use Zend\Code\Generator\FileGenerator;
    
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
        
        public function xmlToForm($file, $fileId) {
            
            
            $globalConfig = $this->sm->get('config');
            
            //make dir for current form
            exec("mkdir " . $globalConfig['surveyFormDir']);
            
            $reader = new Xml();
            $xml = $reader->fromFile($file);
            
            $form = $this->getEntityManager()->getRepository('Administration\Entity\Form')
            ->findOneBy(array('name' => $xml['h:head']["h:title"]));
            
            if (!$form) {
                $form = new Form();
                $form->setName($xml['h:head']["h:title"]);
                $form->setTitle($xml['h:head']["h:title"]);
                
                $formName = ucfirst(preg_replace("/[^a-zA-Z0-9]+/", "", $xml['h:head']["h:title"]) . 'Form');
                
                $checkFormName = $this->getEntityManager()->getRepository('Administration\Entity\Form')
                ->findOneBy(array('formName' => $formName));
                
                $file = $this->getEntityManager()->getRepository('Administration\Entity\File')
                ->findOneBy(array('id' => $fileId));
                $form->setFile($file);
                
                try {
                    if ($checkFormName)
                        throw new \Exception('Error with form name, you have one with same name');
                    else
                        $form->setFormName($formName);
                } catch (\Exception $e) {
                    unlink($file);
                    
                    $this->getEntityManager()->remove($file);
                    $this->getEntityManager()->flush();
                    
                    $this->flashMessenger()->addMessage($e->getMessage());
                    return $this->redirect()->toRoute('survey');
                }
                
                //todo get current session country
                $country = $this->getEntityManager()->getRepository('Administration\Entity\CodeCountries')
                ->findOneBy(array('id' => 2));
                $form->setCountry($country);
                
                $this->getEntityManager()->persist($form);
                $this->getEntityManager()->flush();
                
                $survey = new Survey();
                $survey->setName($xml['h:head']["h:title"]);
                $survey->setCountry($country);
                $survey->setForm($form);
                
                $this->getEntityManager()->persist($survey);
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
            
            $this->generateForm($form);
            
        }
        
        private function groupFormElement ($field, $fieldset = null, $xml) {
            
            foreach ($field['group'] as $formEl) {
                $groupLabel = '';
                
                $formElement = new FormElement();
                $formElement->setParentType('group');
                $formElement->setFieldset($fieldset);
                
                preg_match("/('(.*?)')/", $formEl['label']['ref'], $optionMatch);
                
                foreach ($xml['h:head']['model']['itext']['translation']['text'] as $sub) {
                    if ($sub['id'] === $optionMatch[2]) {
                        $formElement->setLabel($sub['value']);
                        $groupLabel = $sub['value'];
                        $formElement->setName(preg_replace("/[^a-zA-Z0-9]+/", "", strtolower($sub['value'])));
                        break;
                    }
                }
                
                $this->getEntityManager()->persist($formElement);
                $this->getEntityManager()->flush();
                
                if (isset($formEl['repeat'])) {
                    $this->repeatFormElement($formEl['repeat'], $fieldset, $xml, $formElement->getId(), $groupLabel);
                }
            }
            
        }
        
        private function repeatFormElement ($formEl, $fieldset = null, $xml, $parentId = '', $groupLabel = '') {
            
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
            $this->structFormElement($formEl, $fieldset, $xml, $key, $formElement->getId(), $groupLabel, 1);
            
        }
        
        private function structFormElement ($field, $fieldset = null, $xml, $type, $parentId = '', $groupLabel = '', $parentRepeat = 0) {
            
            if (isset($field[$type][0])) {
                foreach ($field[$type] as $formEl) {
                    
                    $formElement = new FormElement();
                    $formElement->setRef($formEl['ref']);
                    
                    if (isset($parentId))
                        $formElement->setParentId($parentId);
                    
                    if (isset($groupLabel))
                        $formElement->setGroupLabel($groupLabel);
                    
                    if (isset($parentRepeat))
                        $formElement->setParentRepeat($parentRepeat);
                    
                    $formElement->setParentType($type);
                    $formElement->setFieldset($fieldset);
                    
                    if (isset($formEl['mediatype']))
                        $formElement->setMediaType(substr($formEl['mediatype'], 0, -2));
                    
                    foreach ($xml['h:head']['model']['itext']['translation']['text'] as $sub) {
                        if ($sub['id'] === ($formEl['ref'] . ':label')) {
                            $formElement->setLabel($sub['value']);
                            $formElement->setName(preg_replace("/[^a-zA-Z0-9]+/", "", strtolower($sub['value'])));
                            break;
                        }
                    }
                    
                    foreach ($xml['h:head']['model']['bind'] as $bind) {
                        if ($bind['nodeset'] === $formEl['ref']) {
                            $formElement->setType($bind['type']);
                            if (isset($bind['required']))
                                $formElement->setRequired("required");
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
                
                if (isset($parentId))
                    $formElement->setParentId($parentId);
                
                if (isset($groupLabel))
                    $formElement->setGroupLabel($groupLabel);
                
                if (isset($parentRepeat))
                    $formElement->setParentRepeat($parentRepeat);
                
                $formElement->setParentType($type);
                $formElement->setFieldset($fieldset);
                
                if (isset($field[$type]['mediatype']))
                    $formElement->setMediaType(substr($field[$type]['mediatype'], 0, -2));
                
                foreach ($xml['h:head']['model']['itext']['translation']['text'] as $sub) {
                    if ($sub['id'] === ($field[$type]['ref'] . ':label')) {
                        $formElement->setLabel($sub['value']);
                        $formElement->setName(preg_replace("/[^a-zA-Z0-9]+/", "", strtolower($sub['value'])));
                        break;
                    }
                }
                
                foreach ($xml['h:head']['model']['bind'] as $bind) {
                    if ($bind['nodeset'] === $field[$type]['ref']) {
                        $formElement->setType($bind['type']);
                        if (isset($bind['required']))
                            $formElement->setRequired("required" /*substr($bind['required'], 0, -2) == "true" ? 1 : 0*/);
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
                        $fieldset->setFieldsetName(ucfirst(preg_replace("/[^a-zA-Z0-9]+/", "", $sub['value']) . 'Fieldset'));
                        $this->getEntityManager()->persist($fieldset);
                        $this->getEntityManager()->flush();
                    }
                    return $fieldset;
                }
            }
            
        }
        
        private function generateForm ($form) {
            
            $globalConfig = $this->sm->get('config');
            
            //make dir for current form
            exec("mkdir " . $globalConfig['surveyFormDir'] . $form->getFormName());
            
            $fieldsets = $this->getEntityManager()->getRepository('Administration\Entity\FormFieldset')
            ->findBy(array('form' => $form));
            
            $fieldsetCode = '';
            //generate fieldsets
            foreach ($fieldsets as $fieldset) {
                
                $fieldsetCode .= "\$this->add(array(\n\t'type' => 'Administration\\Form\\SurveyForm\\" . $form->getFormName()
                . '\\' . $fieldset->getFieldsetName() . "', \n\t'name' => '" . strtolower($fieldset->getFieldsetName())
                . "', \n\t'options' => array(\n\t\t'label' => '" . $fieldset->getName() . "',\n\t),\n));\n\n";
                
                $this->generateFieldset($fieldset, $form);
            }
            
            $formClass = new ClassGenerator();
            $formClass->setName($form->getFormName())
            ->addUse('Zend\Form\Form')
            ->setExtendedClass('Form')
            ->setNamespaceName('Administration\Form\SurveyForm\\' . $form->getFormName())
            ->addMethods(array(
                               MethodGenerator::fromArray(array(
                                                                'name' => '__construct',
                                                                'parameters' => array('name = null'),
                                                                'body'       => "parent::__construct('" . $form->getFormName() . "');" . "\n\n"
                                                                . "\$this->add(array(\n\t'name' => 'id',\n\t'type' => 'Hidden'\n));" . "\n\n" . $fieldsetCode,
                                                                )
                                                          ),
                               ));
            
            $file = FileGenerator::fromArray(array(
                                                   'classes'  => array($formClass),
                                                   ));
            
            $fileCode = $file->generate();
            file_put_contents($globalConfig['surveyFormDir'] . $form->getFormName() . '/' . $form->getFormName()
                              . '.php', $fileCode);
            
        }
        
        private function generateFieldset ($fieldset, $form) {
            
            $globalConfig = $this->sm->get('config');
            
            $formElements = $this->getEntityManager()->getRepository('Administration\Entity\FormElement')
            ->findBy(array('fieldset' => $fieldset));
            
            $formElCode = '';
            foreach ($formElements as $formEl) {
                
                try {
                    if ($formEl->getType()) {
                        $method = 'generate' . ucfirst($formEl->getType()) . 'Field';
                        $formElCode .= $this->$method($formEl);
                    }
                } catch (\Exception $e) {
                    $this->flashMessenger()->addMessage('Error on generating form elements: ' . $e->getMessage());
                    return $this->redirect()->toRoute('survey');
                }
            }
            
            $fieldsetClass = new ClassGenerator();
            $fieldsetClass->setName($fieldset->getFieldsetName())
            ->addUse('Zend\Form\Fieldset')
            ->setExtendedClass('Fieldset')
            ->setNamespaceName('Administration\Form\SurveyForm\\' . $form->getFormName())
            ->addMethods(array(
                               MethodGenerator::fromArray(array(
                                                                'name' => '__construct',
                                                                'parameters' => array('name = null'),
                                                                'body'       => "parent::__construct('" . $fieldset->getFieldsetName() . "');" . "\n\n" . $formElCode,
                                                                )
                                                          ),
                               ));
            
            $file = FileGenerator::fromArray(array(
                                                   'classes'  => array($fieldsetClass),
                                                   ));
            
            $fileCode = $file->generate();
            file_put_contents($globalConfig['surveyFormDir'] . $form->getFormName() . '/' . $fieldset->getFieldsetName()
                              . '.php', $fileCode);
            
        }
        
        //functions are used dynamically
        private function generateStringField ($formEl) {
            
            $name = $formEl->getName();
            if ($formEl->getParentRepeat() != 0)
                $name = $formEl->getName() . '[' . $formEl->getParentRepeat() . ']';
            
            if ($formEl->getParentId() != 0)
                return "\$this->add(array(\n\t'name' => '" . $name
                . "',\n\t'type' => 'Text',\n\t'options' => array(\n\t\t'label' => '" . $formEl->getLabel()
                . "',\n\t),\n\t'attributes' => array(\n\t\t'required' => '" . $formEl->getRequired()
                . "',\n\t\t'data-parent' => " . $formEl->getParentId()
                . ",\n\t\t'data-groupLabel' => '" . $formEl->getGroupLabel() . "',\n\t),\n));\n\n";
            else
                return "\$this->add(array(\n\t'name' => '" . $formEl->getName()
                . "',\n\t'type' => 'Text',\n\t'options' => array(\n\t\t'label' => '" . $formEl->getLabel()
                . "',\n\t),\n\t'attributes' => array(\n\t\t'required' => '" . $formEl->getRequired() . "',\n\t),\n));\n\n";
        }
        
        private function generateDateField ($formEl) {
            
            $name = $formEl->getName();
            if ($formEl->getParentRepeat() != 0)
                $name = $formEl->getName() . '[' . $formEl->getParentRepeat() . ']';
            
            if ($formEl->getParentId() != 0)
                return "\$this->add(array(\n\t'name' => '" . $name
                . "',\n\t'type' => 'Date',\n\t'options' => array(\n\t\t'label' => '" . $formEl->getLabel()
                . "',\n\t),\n\t'attributes' => array(\n\t\t'required' => '" . $formEl->getRequired()
                . "',\n\t\t'data-parent' => " . $formEl->getParentId()
                . ",\n\t\t'data-groupLabel' => '" . $formEl->getGroupLabel() . "',\n\t),\n));\n\n";
            else
                return "\$this->add(array(\n\t'name' => '" . $formEl->getName()
                . "',\n\t'type' => 'Date',\n\t'options' => array(\n\t\t'label' => '" . $formEl->getLabel()
                . "',\n\t),\n\t'attributes' => array(\n\t\t'required' => '" . $formEl->getRequired() . "',\n\t),\n));\n\n";
            
        }
        
        private function generateTimeField ($formEl) {
            
            $name = $formEl->getName();
            if ($formEl->getParentRepeat() != 0)
                $name = $formEl->getName() . '[' . $formEl->getParentRepeat() . ']';
            
            if ($formEl->getParentId() != 0)
                return "\$this->add(array(\n\t'name' => '" . $name
                . "',\n\t'type' => 'Time',\n\t'options' => array(\n\t\t'label' => '" . $formEl->getLabel()
                . "',\n\t),\n\t\t\t'attributes' => array(\n\t\t'required' => '" . $formEl->getRequired()
                . "',\n\t\t'data-parent' => " . $formEl->getParentId()
                . ",\n\t\t'data-groupLabel' => '" . $formEl->getGroupLabel() . "',\n\t),\n));\n\n";
            else
                return "\$this->add(array(\n\t'name' => '" . $formEl->getName()
                . "',\n\t'type' => 'Time',\n\t'options' => array(\n\t\t'label' => '" . $formEl->getLabel()
                . "',\n\t),\n\t'attributes' => array(\n\t\t'required' => '" . $formEl->getRequired() . "',\n\t),\n));\n\n";
        }
        
        private function generateDateTimeField ($formEl) {
            
            $name = $formEl->getName();
            if ($formEl->getParentRepeat() != 0)
                $name = $formEl->getName() . '[' . $formEl->getParentRepeat() . ']';
            
            if ($formEl->getParentId() != 0)
                return "\$this->add(array(\n\t'name' => '" . $name
                . "',\n\t'type' => 'DateTime',\n\t'options' => array(\n\t\t'label' => '" . $formEl->getLabel()
                . "',\n\t),\n\t'attributes' => array(\n\t\t'required' => '" . $formEl->getRequired()
                . "',\n\t\t'data-parent' => " . $formEl->getParentId()
                . ",\n\t\t'data-groupLabel' => '" . $formEl->getGroupLabel() . "',\n\t),'\n));\n\n";
            else
                return "\$this->add(array(\n\t'name' => '" . $formEl->getName()
                . "',\n\t'type' => 'DateTime',\n\t'options' => array(\n\t\t'label' => '" . $formEl->getLabel()
                . "',\n\t),\n\t'attributes' => array(\n\t\t'required' => '" . $formEl->getRequired() . "',\n\t),\n));\n\n";
        }
        
        private function generateSelectField ($formEl) {
            
            $stringArray = 'array(';
            foreach ((array) json_decode($formEl->getValueOptions()) as $key => $val) {
                $stringArray .= '\'' . $key . '\'=>\'' . $val . '\', ';
            }
            $stringArray .= ')';
            
            $name = $formEl->getName();
            if ($formEl->getParentRepeat() != 0)
                $name = $formEl->getName() . '[' . $formEl->getParentRepeat() . ']';
            
            if ($formEl->getParentId() != 0)
                return "\$this->add(array(\n\t'name' => '" . $name
                . "',\n\t'type' => 'Select',\n\t'options' => array(\n\t\t'label' => '" . $formEl->getLabel()
                . "',\n\t\t'value_options' => " . $stringArray . ",\n\t),\n\t'attributes' => array(\n\t\t'required' => '"
                . $formEl->getRequired() . "',\n\t\t'data-parent' => "
                . $formEl->getParentId() . ",\n\t\t'data-groupLabel' => '" . $formEl->getGroupLabel() . "',\n\t),\n));\n\n";
            else
                return "\$this->add(array(\n\t'name' => '" . $formEl->getName()
                . "',\n\t'type' => 'Select',\n\t'options' => array(\n\t\t'label' => '" . $formEl->getLabel()
                . "',\n\t\t'value_options' => " . $stringArray . ",\n\t),
                \n\t'attributes' => array(\n\t\t'required' => '" . $formEl->getRequired() . "',\n\t),\n));\n\n";
        }
        
        private function generateSelect1Field ($formEl) {
            
            $stringArray = 'array(';
            foreach ((array) json_decode($formEl->getValueOptions()) as $key => $val) {
                $stringArray .= '\'' . $key . '\'=>\'' . $val . '\', ';
            }
            $stringArray .= ')';
            
            $name = $formEl->getName();
            if ($formEl->getParentRepeat() != 0)
                $name = $formEl->getName() . '[' . $formEl->getParentRepeat() . ']';
            
            if ($formEl->getParentId() != 0)
                return "\$this->add(array(\n\t'name' => '" . $name
                . "',\n\t'type' => 'Radio',\n\t'options' => array(\n\t\t'label' => '" . $formEl->getLabel()
                . "',\n\t\t'value_options' => " . $stringArray . ",\n\t),\n\t'attributes' => array(\n\t\t'required' => '"
                . $formEl->getRequired() . "',\n\t\t'data-parent' => "
                . $formEl->getParentId() . ",\n\t\t'data-groupLabel' => '" . $formEl->getGroupLabel() . "',\n\t),\n));\n\n";
            else
                return "\$this->add(array(\n\t'name' => '" . $formEl->getName()
                . "',\n\t'type' => 'Radio',\n\t'options' => array(\n\t\t'label' => '" . $formEl->getLabel()
                . "',\n\t\t'value_options' => " . $stringArray . ",\n\t),
                \n\t'attributes' => array(\n\t\t'required' => '" . $formEl->getRequired() . "',\n\t),\n));\n\n";
        }
        
        private function generateIntField ($formEl) {
            
            $name = $formEl->getName();
            if ($formEl->getParentRepeat() != 0)
                $name = $formEl->getName() . '[' . $formEl->getParentRepeat() . ']';
            
            if ($formEl->getParentId() != 0)
                return "\$this->add(array(\n\t'name' => '" . $name
                . "',\n\t'type' => 'Text',\n\t'options' => array(\n\t\t'label' => '" . $formEl->getLabel()
                . "',\n\t),\n\t'attributes' => array(\n\t\t'required' => '" . $formEl->getRequired()
                . "',\n\t\t'data-parent' => " . $formEl->getParentId()
                . ",\n\t\t'data-groupLabel' => '" . $formEl->getGroupLabel() . "',\n\t),\n));\n\n";
            else
                return "\$this->add(array(\n\t'name' => '" . $formEl->getName()
                . "',\n\t'type' => 'Text',\n\t'options' => array(\n\t\t'label' => '" . $formEl->getLabel()
                . "',\n\t),\n\t'attributes' => array(\n\t\t'required' => '" . $formEl->getRequired() . "',\n\t),\n));\n\n";
        }
        
        private function generateBinaryField ($formEl) {
            
            $name = $formEl->getName();
            if ($formEl->getParentRepeat() != 0)
                $name = $formEl->getName() . '[' . $formEl->getParentRepeat() . ']';
            
            //todo check for video and image
            if ($formEl->getParentId() != 0)
                return "\$this->add(array(\n\t'name' => '" . $name
                . "',\n\t'type' => 'File',\n\t'options' => array(\n\t\t'label' => '" . $formEl->getLabel()
                . "',\n\t),\n\t'attributes' => array(\n\t\t'required' => '" . $formEl->getRequired()
                . "',\n\t\t'data-parent' => " . $formEl->getParentId()
                . ",\n\t\t'data-groupLabel' => '" . $formEl->getGroupLabel() . "',\n\t),\n));\n\n";
            else
                return "\$this->add(array(\n\t'name' => '" . $formEl->getName()
                . "',\n\t'type' => 'File',\n\t'options' => array(\n\t\t'label' => '" . $formEl->getLabel()
                . "',\n\t),\n\t'attributes' => array(\n\t\t'required' => '" . $formEl->getRequired() . "',\n\t),\n));\n\n";
        }
        
        private function generateGeopointField ($formEl) {
            
            $name = $formEl->getName();
            if ($formEl->getParentRepeat() != 0)
                $name = $formEl->getName() . '[' . $formEl->getParentRepeat() . ']';
            
            //todo set google maps as element
            if ($formEl->getParentId() != 0)
                return "\$this->add(array(\n\t'name' => '" . $name
                . "',\n\t'type' => 'Password',\n\t'options' => array(\n\t\t'label' => '" . $formEl->getLabel()
                . "',\n\t),\n\t'attributes' => array(\n\t\t'required' => '" . $formEl->getRequired()
                . "',\n\t\t'data-parent' => " . $formEl->getParentId()
                . ",\n\t\t'data-groupLabel' => '" . $formEl->getGroupLabel() . "',\n\t),\n));\n\n";
            else
                return "\$this->add(array(\n\t'name' => '" . $formEl->getName()
                . "',\n\t'type' => 'Password',\n\t'options' => array(\n\t\t'label' => '" . $formEl->getLabel()
                . "',\n\t),\n\t'attributes' => array(\n\t\t'required' => '" . $formEl->getRequired() . "',\n\t),\n));\n\n";
        }
        
    }
