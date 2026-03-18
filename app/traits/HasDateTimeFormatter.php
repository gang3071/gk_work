<?php

namespace app\traits;


trait HasDateTimeFormatter
{
    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format($this->getDateFormat());
    }
}