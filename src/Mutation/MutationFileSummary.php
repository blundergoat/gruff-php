<?php

declare(strict_types=1);

namespace GruffPhp\Mutation;

final readonly class MutationFileSummary
{
    public function __construct(
        public string $filePath,
        public int $totalMutants,
        public int $killedMutants,
        public int $survivedMutants,
        public int $notCoveredMutants,
        public float $msi,
        public float $coveredMsi,
    ) {
    }

    /**
     * @return array{
     *     file: string,
     *     totalMutants: int,
     *     killedMutants: int,
     *     survivedMutants: int,
     *     notCoveredMutants: int,
     *     msi: float,
     *     coveredMsi: float
     * }
     */
    public function toArray(): array
    {
        return [
            'file' => $this->filePath,
            'totalMutants' => $this->totalMutants,
            'killedMutants' => $this->killedMutants,
            'survivedMutants' => $this->survivedMutants,
            'notCoveredMutants' => $this->notCoveredMutants,
            'msi' => $this->msi,
            'coveredMsi' => $this->coveredMsi,
        ];
    }
}
