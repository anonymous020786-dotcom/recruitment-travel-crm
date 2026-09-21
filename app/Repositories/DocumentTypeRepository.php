<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\DocumentType;
use App\Support\Db;

final class DocumentTypeRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<DocumentType> active types, sort order then label */
    public function active(): array
    {
        $rows = $this->db->select('SELECT * FROM document_types WHERE is_active = 1 ORDER BY sort_order, label');

        return array_map([DocumentType::class, 'fromRow'], $rows);
    }

    public function find(int $id): ?DocumentType
    {
        $row = $this->db->selectOne('SELECT * FROM document_types WHERE id = :id', ['id' => $id]);

        return $row ? DocumentType::fromRow($row) : null;
    }

    public function findByKey(string $key): ?DocumentType
    {
        $row = $this->db->selectOne('SELECT * FROM document_types WHERE key_name = :k', ['k' => $key]);

        return $row ? DocumentType::fromRow($row) : null;
    }
}
