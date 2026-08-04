<?php

namespace App\Support;

final class CashAccountCapabilityCatalog
{
    public const ALL = ['RECEIVE_FUNDS', 'MAKE_PAYMENTS', 'TRANSFER_IN', 'TRANSFER_OUT', 'DEPOSIT', 'WITHDRAW', 'ISSUE_CHECK', 'CASH_COUNT', 'STATEMENT_IMPORT', 'RECONCILE', 'ALLOW_NEGATIVE_BALANCE'];
}
