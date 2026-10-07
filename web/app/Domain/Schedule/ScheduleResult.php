<?php

declare(strict_types=1);

namespace App\Domain\Schedule;

final readonly class ScheduleResult
{
    /** @param list<array{number:int,due_date:string,amount:string}> $installments */
    public function __construct(
        public string $financed,
        public string $markup,
        public string $total,
        public array $installments,
    ) {}

    /** @return array{financed:string,markup:string,total:string,installments:list<array{number:int,due_date:string,amount:string}>} */
    public function toArray(): array
    {
        return [
            'financed' => $this->financed,
            'markup' => $this->markup,
            'total' => $this->total,
            'installments' => $this->installments,
        ];
    }
}
