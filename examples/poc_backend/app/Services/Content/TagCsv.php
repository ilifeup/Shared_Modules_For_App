<?php

namespace App\Services\Content;

use App\Models\AuditEvent;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * S9 M16 CSV import / export (D20, D29). Columns: name, color, sort, is_active.
 * Import never overwrites: an existing normalized name is skipped and reported.
 * Export neutralises spreadsheet formulas.
 */
class TagCsv
{
    public const MAX_ROWS = 2000;

    public function __construct(private readonly TagService $tags) {}

    public function export(): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for spreadsheet apps
        fputcsv($out, ['name', 'color', 'sort', 'is_active', 'usage_count'], escape: '\\');
        Tag::query()->orderBy('sort')->orderBy('id')->each(function (Tag $tag) use ($out): void {
            fputcsv($out, [self::safe($tag->name), $tag->color ?? '', $tag->sort, $tag->is_active ? 1 : 0, $tag->usageCount()], escape: '\\');
        });
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * @return array{created: list<string>, skipped: list<string>, errors: list<string>}
     */
    public function import(string $csv, User $actor): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split('/\r\n|\n|\r/', trim($csv)) ?: [];
        $result = ['created' => [], 'skipped' => [], 'errors' => []];
        if ($lines === [] || count($lines) - 1 > self::MAX_ROWS) {
            $result['errors'][] = count($lines) === 0 ? '檔案沒有內容。' : '一次最多匯入 '.self::MAX_ROWS.' 筆。';

            return $result;
        }

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), str_getcsv(array_shift($lines), escape: '\\'));
        $nameAt = array_search('name', $header, true);
        if ($nameAt === false) {
            $result['errors'][] = '第一列需包含 name 欄位。';

            return $result;
        }
        $col = fn (array $row, string $key): ?string => ($i = array_search($key, $header, true)) !== false && isset($row[$i]) ? trim((string) $row[$i]) : null;

        foreach ($lines as $n => $line) {
            if (trim($line) === '') {
                continue;
            }
            $row = str_getcsv($line, escape: '\\');
            $name = ltrim((string) ($row[$nameAt] ?? ''), "'");
            $label = '第 '.($n + 2).' 列';

            if (Tag::query()->where('normalized_name', Tag::normalize($name))->exists()) {
                $result['skipped'][] = $label.'「'.$name.'」已存在';

                continue;
            }

            try {
                $tag = $this->tags->create($name, $col($row, 'color'), (int) ($col($row, 'sort') ?? 0), $actor);
                if (($active = $col($row, 'is_active')) !== null && in_array(strtolower($active), ['0', 'false', 'no'], true)) {
                    $tag->forceFill(['is_active' => false])->save();
                }
                $result['created'][] = $tag->name;
            } catch (ValidationException $e) {
                $result['errors'][] = $label.'：'.collect($e->errors())->flatten()->first();
            }
        }

        AuditEvent::record('tag.imported', $actor->id, null, ['created' => count($result['created']), 'skipped' => count($result['skipped']), 'errors' => count($result['errors'])]);

        return $result;
    }

    /** Prevents CSV formula injection (=, +, -, @, tab, CR). */
    public static function safe(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
