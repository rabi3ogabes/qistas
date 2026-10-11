<?php

namespace App\Domain\Settlement;

use RuntimeException;

/** The payoff the person saw is no longer the payoff (money came in meanwhile): here is the new quote. */
final class QuoteChanged extends RuntimeException
{
    public function __construct(public readonly EarlySettlementQuote $quote)
    {
        parent::__construct('The settlement figure has changed.');
    }
}
