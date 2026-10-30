<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-way Betna rebrand for existing BetnaBot databases.
 *
 * Updates visible text and neutralizes ONLY links to the old operator's
 * website. Does not invent a Betna website URL or overwrite custom links.
 * Other business integrations and historic records are left intact.
 */
return new class extends Migration
{
    private function brand(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = preg_replace('~https?://(?:www\.)?kemerbet\.co[^\s<>"\x27]*~i', 'Betna website (URL not configured)', $text);
        $text = preg_replace('~\bkemer[\s-]*bet\b~iu', 'Betna', $text);
        $text = preg_replace('~kemerbet\.co~i', 'Betna website (URL not configured)', $text);

        return $text;
    }

    private function changeValues(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->brand($value);
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->changeValues($item);
            }
        }
        return $value;
    }

    private function updateTextTable(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        DB::table($table)->orderBy('id')->chunkById(200, function ($records) use ($table, $columns): void {
            foreach ($records as $row) {
                $updates = [];
                foreach ($columns as $column) {
                    $old = $row->{$column} ?? null;
                    if (! is_string($old)) {
                        continue;
                    }
                    $new = $this->brand($old);
                    if ($new !== $old) {
                        $updates[$column] = $new;
                    }
                }
                if ($updates) {
                    DB::table($table)->where('id', $row->id)->update($updates);
                }
            }
        });
    }

    private function updateJsonTable(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }
        DB::table($table)->whereNotNull($column)->orderBy('id')->chunkById(200, function ($records) use ($table, $column): void {
            foreach ($records as $row) {
                $old = $row->{$column};
                $decoded = is_string($old) ? json_decode($old, true) : $old;
                if (! is_array($decoded)) {
                    continue;
                }
                $changed = $this->changeValues($decoded);
                if ($changed !== $decoded) {
                    DB::table($table)->where('id', $row->id)->update([$column => json_encode($changed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                }
            }
        });
    }

    public function up(): void
    {
        $this->updateTextTable('admins', ['name']);
        $this->updateTextTable('menu_item_translations', ['label', 'reply_text']);
        $this->updateTextTable('keyword_reply_translations', ['reply_text']);
        $this->updateTextTable('broadcast_translations', ['text']);
        $this->updateTextTable('broadcast_button_translations', ['label']);
        $this->updateTextTable('automation_step_translations', ['text']);

        // Clear legacy mini-app URL before normalizing other settings values.
        if (Schema::hasTable('settings')) {
            foreach (DB::table('settings')->where('key', 'webapp.url')->get() as $setting) {
                $url = json_decode((string) $setting->value, true);
                if (is_string($url) && str_contains(strtolower($url), 'kemerbet.co')) {
                    DB::table('settings')->where('id', $setting->id)->update(['value' => 'null']);
                }
            }
        }

        foreach ([
            ['settings', 'value'],
            ['broadcasts', 'template_fields'],
            ['broadcasts', 'experiment'],
            ['polls', 'question'],
            ['polls', 'options'],
        ] as [$table, $column]) {
            $this->updateJsonTable($table, $column);
        }

        // Remove legacy external URL buttons from keyword replies and journeys.
        // Their owners can add verified Betna URLs through the admin UI.
        foreach (['keyword_replies', 'automation_steps'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'buttons')) {
                continue;
            }
            DB::table($table)->whereNotNull('buttons')->orderBy('id')->chunkById(200, function ($records) use ($table): void {
                foreach ($records as $row) {
                    $buttons = json_decode((string) $row->buttons, true);
                    if (! is_array($buttons)) {
                        continue;
                    }
                    $clean = array_values(array_filter($buttons, static function ($button): bool {
                        $url = is_array($button) ? ($button['url'] ?? '') : '';
                        return ! (is_string($url) && str_contains(strtolower($url), 'kemerbet.co'));
                    }));
                    if (count($clean) !== count($buttons)) {
                        DB::table($table)->where('id', $row->id)->update(['buttons' => json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                    }
                }
            });
        }

        // Never send users to a competing/old website. Keep the old URL in
        // disabled menu items so the owner can inspect and replace it.
        if (Schema::hasTable('menu_items')) {
            DB::table('menu_items')
                ->where('url', 'ilike', '%kemerbet.co%')
                ->update(['is_active' => false]);
        }

    }

    public function down(): void
    {
        // Rebranding user-visible content cannot be safely undone.
    }
};
