<?php

/**
 *  Copyright notice
 *
 *  (c) 2007-2012 Dominique Feyer (ttree) <dfeyer@ttree.ch>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 * /

/**
 * This view export the current data as XML
 *
 * @author		Dominique Feyer (ttree) <dfeyer@ttree.ch>
 * @package		TYPO3
 * @subpackage	tx_expose
 *
 */
final class Tx_Expose_MVC_View_XMLView extends Tx_Expose_MVC_View_AbstractView {

	/**
	 * @var string
	 */
	protected $version = '1.0';

	/**
	 * @var string
	 */
	protected $encoding = 'UTF-8';

	/**
	 * @var Tx_Expose_Parser_ParserInterface
	 */
	protected $parser;

	/**
	 * @var DOMDocument
	 */
	protected $document;

	/**
	 * Init
	 */
	public function __construct() {
		parent::__construct();

		$this->document = new DOMDocument($this->version, $this->encoding);
		$this->document->formatOutput = TRUE;

		$this->parser = t3lib_div::makeInstance('Tx_Expose_Parser_QueryResultParser');
	}

	/**
	 * Renders the empty view
	 *
	 * @return string An empty string
	 */
	public function render() {
		$rootElementConfigurationElement = $this->getSettingByPath('api.conf.' . $this->rootElementName . '.element');
		if ($rootElementConfigurationElement !== NULL) {
			$rootElementName = $rootElementConfigurationElement;
		} else {
			$rootElementName = $this->rootElementName;
		}
		$rootElement = $this->document->createElement($rootElementName);

		$this->appendAttributes($rootElement, $this->getSettingByPath('api.conf.' . $this->rootElementName));
		$this->document->appendChild($rootElement);

		if ($subRootNodeName = $this->getSettingByPath('api.conf.' . $this->rootElementName . '.subRootNode.element')) {
			$subRootElement = $this->document->createElement($subRootNodeName);
			$rootElement->appendChild($subRootElement);
			$this->renderVariable($subRootElement);
		} else {
			$this->renderVariable($rootElement);
		}

		return $this->document->saveXML();
	}

	/**
	 * @param DOMElement $parentElement
	 *
	 * @return DOMElement
	 * @throws Tx_Expose_Exception_InvalidConfigurationException
	 */
	protected function renderVariable(DOMElement $parentElement) {
		$configurationPath = $this->getSettingByPath('api.conf.' . $this->rootElementName . '.path');
		$configuration = $this->getSettingByPath($configurationPath);

		if (!is_array($configuration) || count($configuration) === 0) {
			throw new Tx_Expose_Exception_InvalidConfigurationException(
				'Invalid webservice configuration',
				1334309979
			);
		}
		foreach ($this->variable as $baseNodeRecord) {
			$rootElement = $this->document->createElement($this->baseElementName);
			if (TRUE == $comment = $this->getSettingByPath('api.conf.' . $this->rootElementName . '.modelComment')) {
				$rootElement->appendChild($this->document->createComment($comment));
			}

			$this->processDomainModel($baseNodeRecord, $configuration, $rootElement);
			$parentElement->appendChild($rootElement);
		}

		return $parentElement;
	}

	/**
	 * @param $record
	 * @param $configuration
	 *
	 * @return bool
	 */
	protected function checkRequiredFields($record, $configuration) {
		foreach ($configuration as $elementConfiguration) {
			if (isset($elementConfiguration['required']) && $elementConfiguration['required'] == TRUE) {
					// Todo add support for cObj, relation and relations
				$value = $this->getElementRawValue($record, $elementConfiguration['path'], $elementConfiguration);
				if (trim($value) === '') {
					return TRUE;
				}
			}
		}

		return FALSE;
	}

	/**
	 * Process each domain model
	 *
	 * @param $record
	 * @param array $configuration
	 * @param DOMElement $rootElement
	 * @param bool       $checkRequired
	 *
	 * @return bool
	 * @throws Tx_Expose_Exception_InvalidConfigurationException
	 */
	protected function processDomainModel($record, array $configuration, DOMElement $rootElement, $checkRequired = FALSE) {

		if ($checkRequired === TRUE && $this->checkRequiredFields($record, $configuration)) {
			return FALSE;
		}

			// Check required fields
		foreach ($configuration as $key => $elementConfiguration) {
			$propertyPath = $elementConfiguration['path'];
			$elementName = $elementConfiguration['element'] ? : t3lib_div::camelCaseToLowerCaseUnderscored($elementConfiguration['path']);

				// Create element
			if (trim($elementName) === '') {
				throw new Tx_Expose_Exception_InvalidConfigurationException(
					'Element name can not be empty',
					1334310000
				);
			}
			$element = $this->document->createElement($elementName);

				// check if we need to add attributes
			$this->appendAttributes($element, $elementConfiguration, $record);

			if (empty($elementConfiguration['type']) || !isset($elementConfiguration['type'])) {
				$elementConfiguration['type'] = 'text';
			}

			switch ($elementConfiguration['type']) {
				case 'text':
					$this->appendTextChild($element, $record, $propertyPath, $elementConfiguration);
					break;
				case 'cdata':
					$this->appendCdataTextChild($element, $record, $propertyPath, $elementConfiguration);
					break;
				case 'complex' :
					$this->appendComplexChildNode($element, $record, $elementConfiguration);
					break;
				case 'relation':
					$this->appendSingleChildrenNode($element, $record, $propertyPath, $elementConfiguration);
					break;
				case 'relations':
					$this->appendMultipleChildrenNodes($element, $record, $propertyPath, $elementConfiguration);
					break;
				default:
					throw new Tx_Expose_Exception_InvalidConfigurationException(
						sprintf('Invalid element type (%s) configuration', $elementConfiguration['type']),
						1334310013
					);
			}

				// do not append if null
			if ($element instanceof DOMElement) {
				$rootElement->appendChild($element);
			}
		}

		return TRUE;
	}

	/**
	 * Append XML text node
	 *
	 * @param DOMElement $element
	 * @param object|array $record
	 * @param string $propertyPath
	 * @param array $elementConfiguration
	 * @return void
	 */
	protected function appendTextChild(DOMElement $element, $record, $propertyPath, array $elementConfiguration) {
		if ($elementValue = $this->getElementValue($record, $propertyPath, $elementConfiguration, FALSE)) {
			$element->appendChild($this->document->createTextNode($elementValue));
		}
	}

	/**
	 * Append XML CDATA Text node
	 *
	 * @param DOMElement   $element
	 * @param object|array $record
	 * @param string       $propertyPath
	 * @param array        $elementConfiguration
	 *
	 * @return void
	 */
	protected function appendCdataTextChild(DOMElement $element, $record, $propertyPath, array $elementConfiguration) {
		if ($elementValue = $this->getElementValue($record, $propertyPath, $elementConfiguration, FALSE)) {
			$element->appendChild($this->document->createCDATASection($elementValue));
		}
	}

	/**
	 * Process a multiple relations
	 * Use explicit reference of $parentElement to handle the case
	 * when removeIfEmpty is set.
	 *
	 * @param DOMElement   $parentElement
	 * @param object|array $record
	 * @param string       $propertyPath
	 * @param array        $elementConfiguration
	 *
	 * @throws Tx_Expose_Exception_InvalidConfigurationException
	 * @return void
	 */
	protected function appendMultipleChildrenNodes(DOMElement &$parentElement, $record, $propertyPath, array $elementConfiguration) {
		$relations = Tx_Extbase_Reflection_ObjectAccess::getPropertyPath($record, $propertyPath);
		if (!empty($elementConfiguration['conf']['removeIfEmpty']) && count($relations) < 1) {
			$parentElement = NULL;
		} else {
			if (!is_array($elementConfiguration['conf']) && trim($elementConfiguration['conf']) === '') {
				throw new Tx_Expose_Exception_InvalidConfigurationException(
					'Unable to process relations without configuration',
					1334310033
				);
			}

			$relationConfigurationPath      = $this->getSettingByPath($elementConfiguration['conf']['path']);
			$relationConfigurationUseParent = !empty($elementConfiguration['conf']['useParentNode']);
			if (!is_array($relationConfigurationPath) || trim($elementConfiguration['children']) === '') {
				throw new Tx_Expose_Exception_InvalidConfigurationException(
					'Invalid configuration',
					1334310035
				);
			}

			$relationRootElement = $this->document->createElement($elementConfiguration['children']);
			foreach ($relations as $record) {
				if ($this->processDomainModel($record, $relationConfigurationPath, $relationRootElement, TRUE)) {
					if (!empty($relationConfigurationUseParent) && $relationRootElement->childNodes instanceof DOMNodeList) {
						$parentElement->appendChild($relationRootElement->firstChild);
					} else {
						$parentElement->appendChild($relationRootElement);
					}
				}
			}
		}
	}

	/**
	 * Process a multiple relations
	 *
	 * @param DOMElement   $element
	 * @param object|array $record
	 * @param string       $propertyPath
	 * @param array        $elementConfiguration
	 *
	 * @throws Tx_Expose_Exception_InvalidConfigurationException
	 * @return void
	 */
	protected function appendSingleChildrenNode(DOMElement $element, $record, $propertyPath, array $elementConfiguration) {
		if (trim($elementConfiguration['conf']) === '') {
			throw new Tx_Expose_Exception_InvalidConfigurationException(
				'Unable to process relations without configuration',
				1334310033
			);
		}

		$relationRecord = Tx_Extbase_Reflection_ObjectAccess::getPropertyPath($record, $propertyPath);
		$relationConfiguration = $this->getSettingByPath($elementConfiguration['conf']);

		if (!is_array($relationConfiguration)) {
			throw new Tx_Expose_Exception_InvalidConfigurationException(
				'Invalid configuration',
				1334310035
			);
		}

		$this->processDomainModel($relationRecord, $relationConfiguration, $element, TRUE);
	}

	/**
	 * Process a node as complex type
	 *
	 * @param DOMElement   $element
	 * @param object|array $record
	 * @param array        $elementConfiguration
	 *
	 * @throws Tx_Expose_Exception_InvalidConfigurationException
	 * @return void
	 */
	protected function appendComplexChildNode(DOMElement $element, $record, array $elementConfiguration = array()) {
		if (!is_array($elementConfiguration['nodes']) && trim($elementConfiguration['nodes']) === '') {
			throw new Tx_Expose_Exception_InvalidConfigurationException(
				'Unable to process complex element without nodes definition',
				1346688712
			);
		}
		foreach ($elementConfiguration['nodes'] as $nodeRecordConfiguration) {
			$this->processDomainModel($record, $nodeRecordConfiguration, $element, FALSE);
		}
	}

	/**
	 * Process attributes if any are configured
	 *
	 * @param DOMElement   $element
	 * @param array        $elementConfiguration
	 * @param array|object $record
	 *
	 * @return void
	 */
	protected function appendAttributes(DOMElement $element, $elementConfiguration, $record = NULL) {
		if (!empty($elementConfiguration['attributes']) && is_array($elementConfiguration['attributes'])) {
			foreach ($elementConfiguration['attributes'] as $attribute => $attributeConfiguration) {
				$fetchedAttributeValue = '';

				if (!empty($attributeConfiguration['path']) && $record !== NULL) {
					$fetchedAttributeValue = $this->getElementValue($record, $attributeConfiguration['path'], $attributeConfiguration, FALSE);
				}
				if (empty($fetchedAttributeValue) && !empty($attributeConfiguration['attributeValue'])) {
					$fetchedAttributeValue = $attributeConfiguration['attributeValue'];
				}

				if (!empty($fetchedAttributeValue)) {
					if (!empty($attributeConfiguration['attributeName'])) {
						$attribute = $attributeConfiguration['attributeName'];
					}
					$domAttribute        = $this->document->createAttribute($attribute);
					$domAttribute->value = $fetchedAttributeValue;
					$element->appendChild($domAttribute);
				}
			}
		}
	}

	/**
	 * Set XML Encoding
	 *
	 * @param string $encoding
	 * @return void
	 */
	public function setEncoding($encoding) {
		$this->encoding = $encoding;
	}

	/**
	 * Set XML Version
	 *
	 * @param string $version
	 * @return void
	 */
	public function setVersion($version) {
		$this->version = $version;
	}

}

?>