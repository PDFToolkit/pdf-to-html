<?php

declare(strict_types=1);

namespace PDF\Html\Forms;

enum FieldType: string
{
    case Text = 'Tx';
    case Button = 'Btn';
    case Choice = 'Ch';
    case Signature = 'Sig';
}
