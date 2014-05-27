<?php

namespace PropertySuggester\Suggesters;

use LoadBalancer;
use InvalidArgumentException;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\PropertyId;
use ResultWrapper;
use Wikibase\DataModel\Snak\Snak;

/**
 * a Suggester implementation that creates suggestion via MySQL
 * Needs the wbs_propertypairs table filled with pair probabilities.
 *
 * @licence GNU GPL v2+
 */
class SimpleSuggester implements SuggesterEngine {

	/**
	 * @var int[]
	 */
	private $deprecatedPropertyIds = array();

	/**
	 * @var LoadBalancer
	 */
	private $lb;

	/**
	 * @param LoadBalancer $lb
	 */
	public function __construct( LoadBalancer $lb ) {
		$this->lb = $lb;
	}

	/**
	 * @param int[] $deprecatedPropertyIds
	 */
	public function setDeprecatedPropertyIds( array $deprecatedPropertyIds ) {
		$this->deprecatedPropertyIds = $deprecatedPropertyIds;
	}

	/**
	 * @param int[] $propertyIds
	 * @param int $limit
	 * @param float $minProbability
	 * @param string $context
	 * @throws InvalidArgumentException
	 * @return Suggestion[]
	 */
	protected function getSuggestions( array $propertyIds, $limit, $minProbability, $context ) {
		if ( !is_int( $limit ) ) {
			throw new InvalidArgumentException('$limit must be int!');
		}
		if ( !is_float( $minProbability ) ) {
			throw new InvalidArgumentException('$minProbability must be float!');
		}
		if ( !$propertyIds ) {
			return array();
		}
		$excludedIds = array_merge( $propertyIds, $this->deprecatedPropertyIds );
		$count = count( $propertyIds );

		$dbr = $this->lb->getConnection( DB_SLAVE );
		$res = $dbr->select(
			'wbs_propertypairs',
			array( 'pid' => 'pid2', 'prob' => "sum(probability)/$count" ),
			array( 'pid1 IN (' . $dbr->makeList( $propertyIds ) . ')',
				   'qid1' => null,
				   'pid2 NOT IN (' . $dbr->makeList( $excludedIds ) . ')',
				   'context' => $context ),
			__METHOD__,
			array(
				'GROUP BY' => 'pid2',
				'ORDER BY' => 'prob DESC',
				'LIMIT'    => $limit,
				'HAVING'   => "prob > $minProbability"
			)
		);
		$this->lb->reuseConnection( $dbr );

		return $this->buildResult($res);
	}

	/**
	 * @see SuggesterEngine::suggestByPropertyIds
	 *
	 * @param PropertyId[] $propertyIds
	 * @param int $limit
	 * @param float $minProbability
	 * @param string $context
	 * @return Suggestion[]
	 */
	public function suggestByPropertyIds( array $propertyIds, $limit, $minProbability, $context ) {
		$numericIds = array_map( array( $this, 'getNumericIdFromPropertyId' ), $propertyIds);

		return $this->getSuggestions( $numericIds, $limit, $minProbability, $context );
	}

	/**
	 * @see SuggesterEngine::suggestByEntity
	 *
	 * @param Item $item
	 * @param int $limit
	 * @param float $minProbability
	 * @param string $context
	 * @return Suggestion[]
	 */
	public function suggestByItem( Item $item, $limit, $minProbability, $context ) {
		$snaks = $item->getAllSnaks();
		$numericIds = array_map( array( $this, 'getNumericIdFromSnak' ), $snaks);
		return $this->getSuggestions( $numericIds, $limit, $minProbability, $context );
	}

	/**
	 * Converts the rows of the SQL result to Suggestion objects
	 *
	 * @param ResultWrapper $res
	 * @return Suggestion[]
	 */
	protected function buildResult( ResultWrapper $res ) {
		$resultArray = array();
		foreach ( $res as $row ) {
			$pid = PropertyId::newFromNumber( ( int ) $row->pid );
			$suggestion = new Suggestion( $pid, $row->prob );
			$resultArray[] = $suggestion;
		}
		return $resultArray;
	}

	private function getNumericIdFromSnak( Snak $snak ) {
		return $snak->getPropertyId()->getNumericId();
	}

	private function getNumericIdFromPropertyId( PropertyId $propertyId ) {
		return $propertyId->getNumericId();
	}

}
