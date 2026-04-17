<?php

namespace App\Services;

use App\Models\MemberCertificateIssued;
use App\Models\MemberLesson;
use App\Models\MemberLessonProgress;
use App\Models\MemberModule;
use App\Models\Product;
use App\Models\User;

class MemberProgressService
{
    /**
     * IDs de aulas que contam para o produto host: nativas + aulas de módulos embutidos (outra área).
     *
     * @return array<int, int|string>
     */
    public function lessonIdsForMemberAreaHost(Product $product): array
    {
        $nativeIds = MemberLesson::where('product_id', $product->id)->pluck('id')->all();
        $sourceModuleIds = MemberModule::where('product_id', $product->id)
            ->whereNotNull('source_member_module_id')
            ->pluck('source_member_module_id')
            ->unique()
            ->filter()
            ->values()
            ->all();
        if ($sourceModuleIds === []) {
            return array_values(array_unique($nativeIds));
        }
        $embedIds = MemberLesson::whereIn('member_module_id', $sourceModuleIds)->pluck('id')->all();

        return array_values(array_unique(array_merge($nativeIds, $embedIds)));
    }

    /**
     * Total lessons count for product (for completion %).
     */
    public function totalLessonsCount(Product $product): int
    {
        return count($this->lessonIdsForMemberAreaHost($product));
    }

    /**
     * Completed lessons count for user in product.
     */
    public function completedLessonsCount(Product $product, User $user): int
    {
        $ids = $this->lessonIdsForMemberAreaHost($product);
        if ($ids === []) {
            return 0;
        }

        return MemberLessonProgress::query()
            ->forUser($user->id)
            ->whereNotNull('completed_at')
            ->whereIn('member_lesson_id', $ids)
            ->count();
    }

    /**
     * Completion percentage (0-100).
     */
    public function completionPercent(Product $product, User $user): int
    {
        $total = $this->totalLessonsCount($product);
        if ($total === 0) {
            return 100;
        }
        $completed = $this->completedLessonsCount($product, $user);
        return (int) min(100, round(($completed / $total) * 100));
    }

    /**
     * Garante que existe um registro de progresso ao abrir a aula (para "continuar assistindo").
     * Só cria se não existir; não altera aulas já concluídas.
     */
    public function ensureLessonStarted(MemberLesson $lesson, User $user): void
    {
        MemberLessonProgress::firstOrCreate(
            [
                'user_id' => $user->id,
                'member_lesson_id' => $lesson->id,
            ],
            [
                'product_id' => $lesson->product_id,
                'completed_at' => null,
            ]
        );
    }

    /**
     * Mark lesson as completed for user.
     */
    public function markLessonCompleted(int $lessonId, User $user): void
    {
        $lesson = MemberLesson::find($lessonId);
        if (! $lesson) {
            return;
        }
        MemberLessonProgress::updateOrCreate(
            [
                'user_id' => $user->id,
                'member_lesson_id' => $lessonId,
            ],
            [
                'product_id' => $lesson->product_id,
                'completed_at' => now(),
                'progress_percent' => 100,
            ]
        );
    }

    /**
     * Check if user can receive certificate (completion >= config and not already issued).
     */
    public function canIssueCertificate(Product $product, User $user): bool
    {
        $config = $product->member_area_config;
        $cert = $config['certificate'] ?? [];
        if (empty($cert['enabled'])) {
            return false;
        }
        $requiredPercent = (int) ($cert['completion_percent'] ?? 100);
        $percent = $this->completionPercent($product, $user);
        if ($percent < $requiredPercent) {
            return false;
        }
        return ! MemberCertificateIssued::where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->exists();
    }

    /**
     * Issue certificate for user/product (if allowed).
     */
    public function issueCertificate(Product $product, User $user): ?MemberCertificateIssued
    {
        if (! $this->canIssueCertificate($product, $user)) {
            return null;
        }
        $percent = $this->completionPercent($product, $user);
        return MemberCertificateIssued::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'issued_at' => now(),
            'completion_percent' => $percent,
        ]);
    }
}
