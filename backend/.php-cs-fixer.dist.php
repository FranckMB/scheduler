<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude("var")
    ->notPath([
        "config/bundles.php",
        "config/reference.php",
    ])
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        "@PSR12" => true,
        "@Symfony" => true,
        "yoda_style" => [
            "equal" => true,
            "identical" => true,
            "less_and_greater" => null,
        ],
        "strict_comparison" => true,
    ])
    ->setFinder($finder)
;
