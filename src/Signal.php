<?php
namespace Charm\Parsing;

/**
 * Sentinel values for parse results.
 *
 * Using an enum instead of boolean signals allows actual boolean values
 * (true/false) to pass through as parse results without boxing.
 */
enum Signal {
    /** Parse failed - no match */
    case MISS;
    /** Parse succeeded but has no value to add */
    case HIT;
}
