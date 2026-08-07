<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MarinSolutions\CheckybotLaravel\Domain\Security\Foundation\IssuedProjectApiToken;
use MarinSolutions\CheckybotLaravel\Domain\Security\Foundation\ProjectTokenAbility;
use MarinSolutions\CheckybotLaravel\Models\Concerns\HasPublicUuid;

/**
 * @property string $project_id
 * @property string $name
 * @property string $token_hash
 * @property list<string> $abilities
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $revoked_at
 */
final class ProjectApiToken extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['abilities' => 'array', 'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    public function scopeForProject(Builder $query, string $projectId): Builder
    {
        return $query->where('project_id', $projectId);
    }

    /** @param list<ProjectTokenAbility|string> $abilities */
    public static function issue(
        string $projectId,
        string $name,
        array $abilities,
        ?CarbonInterface $expiresAt = null,
    ): IssuedProjectApiToken {
        $plainText = 'cbp_'.Str::random(48);
        $abilityValues = array_values(array_unique(array_map(
            static fn (ProjectTokenAbility|string $ability): string => $ability instanceof ProjectTokenAbility ? $ability->value : $ability,
            $abilities,
        )));

        $token = self::query()->create([
            'project_id' => $projectId,
            'name' => $name,
            'token_hash' => self::hashToken($plainText),
            'abilities' => $abilityValues,
            'expires_at' => $expiresAt,
        ]);

        return new IssuedProjectApiToken($token, $plainText);
    }

    public static function findByPlainText(string $plainText): ?self
    {
        return self::query()->where('token_hash', self::hashToken($plainText))->first();
    }

    public static function authenticate(string $plainText): ?self
    {
        $token = self::findByPlainText($plainText);

        return $token?->isActive() === true ? $token : null;
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function allows(ProjectTokenAbility|string $ability): bool
    {
        $value = $ability instanceof ProjectTokenAbility ? $ability->value : $ability;

        return $this->isActive() && in_array($value, $this->abilities ?? [], true);
    }

    public function revoke(): void
    {
        if ($this->revoked_at === null) {
            $this->forceFill(['revoked_at' => now()])->save();
        }
    }

    /** @param list<ProjectTokenAbility|string>|null $abilities */
    public function rotate(?array $abilities = null, ?CarbonInterface $expiresAt = null): IssuedProjectApiToken
    {
        return DB::transaction(function () use ($abilities, $expiresAt): IssuedProjectApiToken {
            $locked = self::query()->lockForUpdate()->whereKey($this->getKey())->firstOrFail();
            $locked->revoke();

            return self::issue(
                $locked->project_id,
                $locked->name,
                $abilities ?? $locked->abilities,
                $expiresAt ?? $locked->expires_at,
            );
        });
    }

    private static function hashToken(string $plainText): string
    {
        return hash('sha256', $plainText);
    }
}
