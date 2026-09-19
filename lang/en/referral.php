<?php

return [

    'refused' => [
        'self_referral' => 'An account cannot refer itself.',
        'circular' => 'That account is already below this one in the referral chain, so it cannot be its referrer.',
        'locked' => 'This referral has been used by a qualifying event and can no longer change.',
        'referrer_not_active' => 'Only an active business can be a referrer.',
        'plan_overlaps' => 'Another plan version for the same package is already in force from that date. Close it first or choose a later date.',
        'levels_incomplete' => 'Every level from 1 to :depth needs a rule.',
        'level_invalid' => 'The rule for level :level is not valid: a fixed amount must be above zero, and a percentage between 0.01% and 100%.',
        'plan_not_open' => 'That plan version is already closed.',
        'nothing_to_reverse' => 'That commission has nothing left to reverse.',
    ],

];
