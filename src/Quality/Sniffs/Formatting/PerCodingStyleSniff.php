<?php

declare(strict_types=1);

namespace YouTubeMcp\Quality\Sniffs\Formatting;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PhpCsFixer\Fixer\FixerInterface;
use PhpCsFixer\FixerFactory;
use PhpCsFixer\RuleSet\RuleSet;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\WhitespacesFixerConfig;
use SplFileInfo;

/** Check the shared PER preset in memory; PHP-CS-Fixer owns writing changes. */
final class PerCodingStyleSniff implements Sniff
{
    /** @var list<FixerInterface> */
    private array $fixers;

    public function __construct()
    {
        $config = require dirname(__DIR__, 4) . '/.php-cs-fixer.dist.php';
        $this->fixers = (new FixerFactory())
            ->registerBuiltInFixers()
            ->useRuleSet(new RuleSet($config->getRules()))
            ->setWhitespacesConfig(new WhitespacesFixerConfig($config->getIndent(), $config->getLineEnding()))
            ->getFixers();
    }

    /** @return list<int> */
    public function register(): array
    {
        return [T_OPEN_TAG];
    }

    public function process(File $phpcsFile, int $stackPtr): int
    {
        $source = $phpcsFile->getTokensAsString(0, $phpcsFile->numTokens);
        Tokens::clearCache();
        $tokens = Tokens::fromCode($source);
        $file = new SplFileInfo($phpcsFile->getFilename());
        $changes = [];

        foreach ($this->fixers as $fixer) {
            if (!$fixer->supports($file) || !$fixer->isCandidate($tokens)) {
                continue;
            }

            $fixer->fix($file, $tokens);
            if ($tokens->isChanged()) {
                $changes[] = $fixer->getName();
                $tokens->clearEmptyTokens();
                $tokens->clearChanged();
            }
        }

        if ($tokens->generateCode() !== $source) {
            $phpcsFile->addError(
                'PER formatting required (%s). Run composer cs-check for the diff or composer cs-fix to apply it.',
                $stackPtr,
                'Formatting',
                [implode(', ', $changes)],
            );
        }

        return $phpcsFile->numTokens;
    }
}
