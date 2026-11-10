<?php

namespace App\Shared\Tenancy;

class CompanyContext
{
    private ?int $company = null;

    private ?int $actor = null;

    public function company(): int
    {
        abort_unless($this->company && $this->company > 0, 401, 'Company context required.');

        return $this->company;
    }

    public function actor(): ?int
    {
        return $this->actor;
    }

    public function run(int $company, ?int $actor, callable $callback): mixed
    {
        abort_unless($company > 0, 401);
        [$previousCompany, $previousActor] = [$this->company, $this->actor];
        [$this->company, $this->actor] = [$company, $actor];
        try {
            return $callback();
        } finally {
            [$this->company, $this->actor] = [$previousCompany, $previousActor];
        }
    }
}
