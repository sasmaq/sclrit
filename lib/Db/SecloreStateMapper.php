<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSeclore\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<SecloreState>
 */
class SecloreStateMapper extends QBMapper {
	public const TABLE = 'seclore_state';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, SecloreState::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findByFileId(int $fileId): SecloreState {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @param int[] $fileIds
	 * @return SecloreState[]
	 */
	public function findByFileIds(array $fileIds): array {
		if ($fileIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('file_id', $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)));
		return $this->findEntities($qb);
	}

	/**
	 * In-flight rows whose last update is older than the watchdog window (SDD §9 E14).
	 *
	 * @return SecloreState[]
	 */
	public function findStale(int $updatedBefore, int $limit = 100): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('status', $qb->createNamedParameter(
				[SecloreState::STATUS_PENDING, SecloreState::STATUS_PROCESSING],
				IQueryBuilder::PARAM_STR_ARRAY,
			)))
			->andWhere($qb->expr()->lt('updated_at', $qb->createNamedParameter($updatedBefore, IQueryBuilder::PARAM_INT)))
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/**
	 * Most recently updated rows, optionally filtered by status — for
	 * `occ files_seclore:status` (SDD §4.4).
	 *
	 * @return SecloreState[]
	 */
	public function findForOverview(?string $status = null, int $limit = 500): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('updated_at', 'DESC')
			->setMaxResults($limit);
		if ($status !== null) {
			$qb->where($qb->expr()->eq('status', $qb->createNamedParameter($status)));
		}
		return $this->findEntities($qb);
	}

	/**
	 * A batch of rows with id >= $minId, ordered by id — cursor-based paging
	 * for the orphan sweep (SDD §6.1).
	 *
	 * @return SecloreState[]
	 */
	public function findChunk(int $minId, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->gte('id', $qb->createNamedParameter($minId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	public function deleteByFileId(int $fileId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
