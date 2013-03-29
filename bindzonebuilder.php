#!/usr/bin/env php
<?php
class BindZoneBuilder {

	const LE = "\n";
	const TAB = "\t";
	const TAB_SIZE = 4;

	const ZONEHEADER_COMMENT_TABCOUNT = 3;
	const ZONEITEM_TABCOUNT = 5;
	const ZONEITEM_TTL_TABCOUNT = 2;

	private $validRecordTypeList = ['NS','A','CNAME','MX','SPF','TXT'];

	private $configValid = true;
	private $zoneDataList = [];
	private $activeDomainName = false;


	public function __construct(array $argv) {

		// fetch command line options and validate
		if (
			(($optionList = $this->getOptions($argv)) === false) ||
			(!$this->validatePaths($optionList))
		) exit(1);

		// generate zone file data from XML config
		if (!$this->generateZoneDataList($optionList['configFile'])) {
			exit(1);
		}

		// zone file data generated, write back to disk
		if (!$this->writeZoneFiles($optionList['targetDir'])) {
			exit(1);
		}

		// finished successfully
		exit(0);
	}

	private function getOptions(array $argv) {

		$optionList = getopt('c:t:');

		// required options given?
		if (!isset($optionList['c'],$optionList['t'])) {
			// no - display usage
			$this->writeLine(
				'Usage: ' . basename($argv[0]) . ' -c[file] -t[dir]' . self::LE . self::LE .
				'  -c[file]    XML domain configuration file' . self::LE .
				'  -t[dir]     Target directory for generated bind zone files (must exist)' . self::LE
			);

			return false;
		}

		// return options
		return [
			'configFile' => $optionList['c'],
			'targetDir' => rtrim($optionList['t'],'/')
		];
	}

	private function validatePaths(array $optionList) {

		// configuration file
		if (!is_file($optionList['configFile'])) {
			$this->writeLine('Unable to open configuration file - ' . $optionList['configFile'],true);
			return false;
		}

		// target bind zone file directory
		if (!is_dir($optionList['targetDir'])) {
			$this->writeLine('Target directory does not exist or is invalid - ' . $optionList['targetDir'],true);
			return false;
		}

		// all good
		return true;
	}

	private function generateZoneDataList($configFile) {

		// create XML parser and open XML file
		$XMLParser = xml_parser_create();
		xml_set_element_handler($XMLParser,[$this,'XMLTagStart'],[$this,'XMLTagEnd']);

		$fh = fopen($configFile,'r');

		while (($data = fread($fh,4096))) {
			if (!xml_parse($XMLParser,$data,feof($fh))) {
				// XML parse error
				$this->configValid = false;
				$this->writeLine(sprintf(
					'XML parser reports \'%s at line #%d\'' . self::LE,
					xml_error_string(xml_get_error_code($XMLParser)),
					xml_get_current_line_number($XMLParser)
				),true);
			}

			if (!$this->configValid) break;
		}

		// close XML file and parser instance
		fclose($fh);
		xml_parser_free($XMLParser);

		return $this->configValid;
	}

	private function XMLTagStart($XMLParser,$tagName,array $attributeList) {

		if (!$this->configValid) return;

		if (($tagName == 'DOMAIN') && ($this->activeDomainName === false)) {
			// check required attributes exist on domain node
			if (!isset($attributeList['NAME'])) {
				// no name="" on <domain> node
				$this->writeLine('Missing \'name\' attribute on domain node' . $this->getXMLLineNumberMsg($XMLParser),true);
				$this->configValid = false;
				return;
			}

			$this->activeDomainName = trim($attributeList['NAME']);

			if (isset($this->zoneDataList[$this->activeDomainName])) {
				// domain already exists
				$this->writeLine(
					'The domain \'' . $this->activeDomainName . '\' ' .
					'has already been defined, error' . $this->getXMLLineNumberMsg($XMLParser),
					true
				);

				$this->configValid = false;
				return;
			}

			// check all required attributes exist on <domain> node and within valid values
			foreach (['admin','ttl','refresh','retry','expire','negttl'] as $domainAttribute) {
				if (!isset($attributeList[strtoupper($domainAttribute)])) {
					// attribute not found
					$this->writeLine(
						'Domain attribute \'' . $domainAttribute . '\' ' .
						'undefined for \'' . $this->activeDomainName . '\'' . $this->getXMLLineNumberMsg($XMLParser),
						true
					);

					$this->configValid = false;
					return;
				}

				if ($domainAttribute != 'admin') {
					// check timing
					$timeValue = $attributeList[strtoupper($domainAttribute)];
					if (!$this->isValidDomainTime($timeValue)) {
						// invalid time value
						$this->writeLine(
							'Invalid time value \'' . $timeValue . '\' for attribute \'' . $domainAttribute . '\' ' .
							'on domain \'' . $this->activeDomainName . '\'' . $this->getXMLLineNumberMsg($XMLParser),
							true
						);

						$this->configValid = false;
						return;
					}
				}
			}

			// init zoneDataList[] for this domain
			$this->zoneDataList[$this->activeDomainName] = [
				'admin' => $attributeList['ADMIN'],
				'ttl' => $attributeList['TTL'],
				'refresh' => $attributeList['REFRESH'],
				'retry' => $attributeList['RETRY'],
				'expire' => $attributeList['EXPIRE'],
				'negTtl' => $attributeList['NEGTTL'],
				'recordList' => []
			];

			return;
		}

		if (
			($tagName == 'RECORD') &&
			($this->activeDomainName !== false)
		) {
			// add DNS record for the current domain
			if (!$this->addZoneDataRecord($XMLParser,$attributeList)) {
				// error in DNS record definition
				$this->configValid = false;
			}

			return;
		}
	}

	private function XMLTagEnd($XMLParser,$tagName) {

		if (!$this->configValid) return;

		if (
			($tagName == 'DOMAIN') &&
			($this->activeDomainName !== false)
		) {
			// end of domain zone definition
			$this->activeDomainName = false;
		}
	}

	private function addZoneDataRecord($XMLParser,array $attributeList) {

		// validate record data items
		if (!isset($attributeList['TYPE'])) {
			// no record type set
			$this->writeLine(
				'No type defined for record defined in the ' .
				'\'' . $this->activeDomainName . '\' domain' . $this->getXMLLineNumberMsg($XMLParser),
				true
			);

			return false;
		}

		$recordType = $attributeList['TYPE'];
		if (!in_array($recordType,$this->validRecordTypeList)) {
			// invalid record type
			$this->writeLine(
				'Invalid record type \'' . $recordType . '\' defined in the ' .
				'\'' . $this->activeDomainName . '\' domain' . $this->getXMLLineNumberMsg($XMLParser),
				true
			);

			return false;
		}

		if (!isset($attributeList['VALUE'])) {
			// no record value given
			$this->writeLine(
				'No value for record type \'' . $recordType . '\' defined in the ' .
				'\'' . $this->activeDomainName . '\' domain' . $this->getXMLLineNumberMsg($XMLParser),
				true
			);

			return false;
		}

		if (isset($attributeList['TTL'])) {
			// record has a specific TTL value
			$timeValue = $attributeList['TTL'];
			if (!$this->isValidDomainTime($timeValue)) {
				// invalid record time value
				$this->writeLine(
					'Invalid time value \'' . $timeValue . '\' for record type \'' . $recordType . '\' defined in the ' .
					'\'' . $this->activeDomainName . '\' domain' . $this->getXMLLineNumberMsg($XMLParser),
					true
				);

				return false;
			}
		}

		$recordValue = trim($attributeList['VALUE']);
		$recordData = [
			'type' => $recordType,
			'domain' => (isset($attributeList['DOMAIN'])) ? trim($attributeList['DOMAIN']) : false,
			'ttl' => (isset($attributeList['TTL'])) ? $attributeList['TTL'] : false,
			'data' => []
		];

		switch ($recordType) {
			case 'NS':
				$recordData['data'] = [$recordValue];
				break;

			case 'A':
				// ensure $recordValue is a valid IPv4 address
				if (!$this->isValidIPv4Address($recordValue)) {
					$this->writeLine(
						'Invalid IPv4 address \'' . $recordValue . '\' for ANAME record defined in the ' .
						'\'' . $this->activeDomainName . '\' domain' . $this->getXMLLineNumberMsg($XMLParser),
						true
					);

					return false;
				}

				$recordData['data'] = [$recordValue];
				break;

			case 'CNAME':
				$recordData['data'] = [$recordValue];
				break;

			case 'MX':
				// validate priority given and a numeric value
				if (!isset($attributeList['PRIORITY'])) {
					$this->writeLine(
						'No priority set for MX record defined in the ' .
						'\'' . $this->activeDomainName . '\' domain' . $this->getXMLLineNumberMsg($XMLParser),
						true
					);

					return false;
				}

				$priority = $attributeList['PRIORITY'];
				if (!preg_match('/^[1-9]\d{0,2}$/',$priority)) {
					$this->writeLine(
						'Invalid priority of \'' . $priority . '\' set for the MX record defined in the ' .
						'\'' . $this->activeDomainName . '\' domain' . $this->getXMLLineNumberMsg($XMLParser),
						true
					);

					return false;
				}

				$recordData['data'] = [$priority,$recordValue];
				break;

			case 'SPF':
				// validate SPF IPv4 address(es) given and build SPF record in the required format
				if (($recordValue = $this->buildSPFRecord($XMLParser,$recordValue)) === false) {
					// error with SPF record generation
					return false;
				}

				$recordData['data'] = ['"' . $recordValue . '"'];
				break;

			case 'TXT':
				$recordData['data'] = ['"' . $recordValue . '"'];
				break;
		}

		$this->zoneDataList[$this->activeDomainName]['recordList'][] = $recordData;
		return true;
	}

	private function buildSPFRecord($XMLParser,$recordValue) {

		// extract optional trailing 'all' qualifier - neutral/? is the default
		$allQualifier = '?';
		if (preg_match('/\[(.)\]$/',$recordValue,$match)) {
			$allQualifier = $match[1];
			if (!preg_match('/^[+?~-]$/',$allQualifier)) {
				$this->writeLine(
					'Invalid SPF record qualifier \'' . $allQualifier . '\' ' .
					'set for the domain \'' . $this->activeDomainName . '\'' . $this->getXMLLineNumberMsg($XMLParser),
					true
				);

				return false;
			}

			// trim qualifier from tail of SPF definition
			$recordValue = trim(substr($recordValue,0,-3));
		}

		// validate the SPF criteria components and build SPF record
		$SPFRecordComponentList = ['v=spf1'];

		if ($recordValue != '') {
			$SPFCriteriaList = explode(',',$recordValue);
			foreach ($SPFCriteriaList as $SPFCriteriaItem) {
				// IPv4 address or DNS entry (for include:)? Rather basic decision criteria here
				if (preg_match('/^\d{1,3}(.\d{1,3})+$/',$SPFCriteriaItem)) {
					if (!$this->isValidIPv4Address($SPFCriteriaItem)) {
						// error in IPv4 address
						$this->writeLine(
							'Invalid IPv4 address \'' . $SPFCriteriaItem . '\' for SPF record defined in the ' .
							'\'' . $this->activeDomainName . '\' domain' . $this->getXMLLineNumberMsg($XMLParser),
							true
						);

						return false;
					}

					$SPFRecordComponentList[] = 'ip4:' . $SPFCriteriaItem;

				} else {
					// considered a DNS entry
					$SPFRecordComponentList[] = 'include:' . trim($SPFCriteriaItem);
				}
			}
		}

		// add on trailing 'all' qualifier to complete the SPF rule and return result
		$SPFRecordComponentList[] = (($allQualifier == '+') ? '' : $allQualifier) . 'all';
		return implode(' ',$SPFRecordComponentList);
	}

	private function isValidDomainTime($value) {

		return (preg_match('/^[1-9]\d*[wWdDhHmMsS]{0,1}$/',$value)) ? true : false;
	}

	private function isValidIPv4Address($IPv4Address) {

		$partList = explode('.',$IPv4Address);
		if (sizeof($partList) != 4) return false;

		foreach ($partList as $number) {
			if (
				(!preg_match('/^\d{1,3}$/',$number)) ||
				(intval($number) > 255)
			) return false;
		}

		// given IP address is valid
		return true;
	}

	private function getXMLLineNumberMsg($XMLParser) {

		return ' at line #' . xml_get_current_line_number($XMLParser);
	}

	private function writeZoneFiles($zoneFileTargetDirectory) {

		// build base serial number YYYYMMDD
		$baseZoneSerialNumber = date('Ymd');
		$zoneFileContentList = [];

		foreach ($this->zoneDataList as $domainName => $void) {
			$zoneTargetFilePath = $zoneFileTargetDirectory . '/db.' . $domainName;
			$existingZoneFile = $this->parseExistingZoneFile($zoneTargetFilePath);
			if ($existingZoneFile === false) {
				// existing zone file parse error
				return false;
			}

			// determine the need to build a new zone file
			$buildZone = false;
			if (!$existingZoneFile) {
				// no existing zone file found, build new
				$buildZone = true;

			} else {
				// compare generated zone file data to that already saved in the file (compare without zone serial number)
				$zoneContent = $this->buildZoneFileContent($domainName);
				if ($zoneContent === false) {
					// error building zone file content
					return false;
				}

				if ($zoneContent != $existingZoneFile['data']) {
					// new zone file has different record data - rebuild file
					$buildZone = true;
				}
			}

			if ($buildZone) {
				// generate serial number for zone file
				if (
					!$existingZoneFile ||
					($baseZoneSerialNumber != $existingZoneFile['serialBase'])
				) {
					// new file or existing file with different serial number base date - new serial number generated
					$zoneSerialNumber = $baseZoneSerialNumber . '00';

				} else {
					// base serial dates are the same, increment version number
					$zoneSerialNumber = $baseZoneSerialNumber . sprintf('%02d',$existingZoneFile['serialVer'] + 1);
				}

				// generate final zone data to write back to disk
				$zoneContent = $this->buildZoneFileContent($domainName,$zoneSerialNumber);
				if ($zoneContent === false) {
					// error building zone file content
					return false;
				}

				$zoneFileContentList[$zoneTargetFilePath] = $zoneContent;
			}
		}

		// now write out zones in $zoneFileContentList to disk
		foreach ($zoneFileContentList as $zoneTargetFilePath => $content) {
			file_put_contents($zoneTargetFilePath,$content);
			$this->writeLine('Written zone file \'' . $zoneTargetFilePath . '\'');
		}

		// all zone files written successfully
		return true;
	}

	private function buildZoneFileContent($domainName,$serialNumber = false) {

		$zoneData = $this->zoneDataList[$domainName];

		// extract primary nameserver
		$primaryNameServer = false;
		foreach ($zoneData['recordList'] as $item) {
			if ($item['type'] == 'NS') {
				$primaryNameServer = $item['data'][0];
				break;
			}
		}

		if ($primaryNameServer === false) {
			// zone didn't define at least one nameserver
			$this->writeLine('No primary nameserver defined for \'' . $domainName . '\'',true);
			return false;
		}

		// closure to pad out serial/time/domain values
		$tabLeadingText = function($value,$indentCount) {

			$indentSize = ($indentCount * self::TAB_SIZE) - strlen($value);
			if ($indentSize < self::TAB_SIZE) {
				// if value string length is greater than indent then no need to align
				return $value . self::TAB;
			}

			$tabCount = floor($indentSize / self::TAB_SIZE);

			return
				$value .
				((($indentSize % ($tabCount * self::TAB_SIZE)) > 0) ? self::TAB : '') .
				str_repeat(self::TAB,$tabCount);
		};

		// zone file default record TTL and SOA header
		$headerPrefixTab = str_repeat(self::TAB,self::ZONEITEM_TABCOUNT + self::ZONEITEM_TTL_TABCOUNT);
		$zoneFileBuilt =
			'$TTL ' . $zoneData['ttl'] . self::LE .
			'@ IN SOA ' . $primaryNameServer . ' ' . str_replace('@','.',$zoneData['admin']) . '. (' . self::LE;

		if ($serialNumber !== false) {
			// add serial number to zone file data
			$zoneFileBuilt .= $headerPrefixTab . $tabLeadingText($serialNumber,self::ZONEHEADER_COMMENT_TABCOUNT) . '; Serial' . self::LE;
		}

		// add refresh, retry, expire, neg TTL time
		$zoneFileBuilt .=
			$headerPrefixTab . $tabLeadingText($zoneData['refresh'],self::ZONEHEADER_COMMENT_TABCOUNT) . '; Refresh' . self::LE .
			$headerPrefixTab . $tabLeadingText($zoneData['retry'],self::ZONEHEADER_COMMENT_TABCOUNT) . '; Retry' . self::LE .
			$headerPrefixTab . $tabLeadingText($zoneData['expire'],self::ZONEHEADER_COMMENT_TABCOUNT) . '; Expire' . self::LE .
			$headerPrefixTab . $tabLeadingText($zoneData['negTtl'] . ' )',self::ZONEHEADER_COMMENT_TABCOUNT) . '; Negative Cache TTL' . self::LE . self::LE;

		// add zone records
		foreach ($this->validRecordTypeList as $recordType) {
			$recordAdded = false;

			foreach ($zoneData['recordList'] as $recordItem) {
				if ($recordType != $recordItem['type']) continue;
				$recordAdded = true;

				$zoneFileBuilt .=
					$tabLeadingText(
						($recordItem['domain'] === false) ? '@' : $recordItem['domain'],
						self::ZONEITEM_TABCOUNT
					) .
					$tabLeadingText(($recordItem['ttl'] !== false) ? $recordItem['ttl'] : '',self::ZONEITEM_TTL_TABCOUNT) .
					(($recordType == 'SPF') ? 'TXT' : $recordType);

				if ($recordType == 'MX') {
					$zoneFileBuilt .= ' ' . implode(self::TAB . self::TAB,$recordItem['data']);

				} else {
					if ($recordType != 'CNAME') $zoneFileBuilt .= self::TAB;
					$zoneFileBuilt .= self::TAB . self::TAB . $recordItem['data'][0];
				}

				$zoneFileBuilt .= self::LE;
			}

			if ($recordAdded) $zoneFileBuilt .= self::LE;
		}

		// generated zone content
		return rtrim($zoneFileBuilt) . self::LE;
	}

	private function parseExistingZoneFile($zoneFilePath) {

		// does zone file exist?
		if (!is_file($zoneFilePath)) return [];

		$fileData = file_get_contents($zoneFilePath);

		// extract serial number
		if (!preg_match('/\n\t+(\d{8})(\d{2})\t+; Serial\n/',$fileData,$match)) {
			// can't extract serial number from zone file
			$this->writeLine('Unable to extract domain serial number from \'' . $zoneFilePath . '\'',true);
			return false;
		}

		// return serial number and zone file data without serial number line for comparing
		return [
			'serialBase' => $match[1],
			'serialVer' => intval($match[2]),
			// existing zone content with serial number line removed
			'data' => str_replace($match[0],self::LE,$fileData)
		];
	}

	private function writeLine($text = '',$isError = false) {

		echo((($isError) ? 'Error: ' : '') . $text . self::LE);
	}
}


new BindZoneBuilder($argv);
