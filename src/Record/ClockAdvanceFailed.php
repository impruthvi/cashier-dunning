<?php

namespace Impruthvi\CashierDunning\Record;

use RuntimeException;

/**
 * The clock did not reach `ready`. Recording stops here rather than reading a
 * half-advanced world and writing it down as though it were a billing timeline.
 */
class ClockAdvanceFailed extends RuntimeException {}
