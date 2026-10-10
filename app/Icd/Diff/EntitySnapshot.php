<?php

namespace App\Icd\Diff;

/**
 * The fields of one entity that the diff compares. Parents are compared by
 * URI, because parent_id is a per-release surrogate key.
 */
final readonly class EntitySnapshot
{
    public function __construct(
        public string $linearizationUri,
        public ?string $code,
        public ?string $blockId,
        public string $title,
        public string $classKind,
        public string $chapter,
        public ?string $parentUri,
    ) {}

    /** Builds a snapshot from a normalized row or an icd_entities row; other keys are ignored. */
    public static function fromArray(array $row): self
    {
        return new self(
            linearizationUri: $row['linearization_uri'],
            code: $row['code'],
            blockId: $row['block_id'],
            title: $row['title'],
            classKind: $row['class_kind'],
            chapter: $row['chapter'],
            parentUri: $row['parent_uri'],
        );
    }

    /** @return array{linearization_uri: string, code: ?string, block_id: ?string, title: string, class_kind: string, chapter: string, parent_uri: ?string} */
    public function toArray(): array
    {
        return [
            'linearization_uri' => $this->linearizationUri,
            'code' => $this->code,
            'block_id' => $this->blockId,
            'title' => $this->title,
            'class_kind' => $this->classKind,
            'chapter' => $this->chapter,
            'parent_uri' => $this->parentUri,
        ];
    }
}
