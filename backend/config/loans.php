<?php

$approvalLevels = (int) env('LOAN_APPROVAL_LEVELS', 3);

return [
    'approval_levels' => in_array($approvalLevels, [2, 3], true) ? $approvalLevels : 3,
];
