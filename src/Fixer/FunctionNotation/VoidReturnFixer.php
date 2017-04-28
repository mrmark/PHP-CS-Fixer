<?php

/*
 * This file is part of PHP CS Fixer.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *     Dariusz Rumiński <dariusz.ruminski@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace PhpCsFixer\Fixer\FunctionNotation;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\DocBlock\Annotation;
use PhpCsFixer\DocBlock\DocBlock;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\VersionSpecification;
use PhpCsFixer\FixerDefinition\VersionSpecificCodeSample;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;

/**
 * @author Mark Nielsen
 */
final class VoidReturnFixer extends AbstractFixer
{
    /**
     * @internal
     */
    const VOID_RETURN_PATTERN = '/@return\s+void(?!\|)/';

    /**
     * {@inheritdoc}
     */
    public function getDefinition()
    {
        return new FixerDefinition(
            'Add void return type to functions with missing or empty return statements, but priority is given to `@return` annotations. Requires PHP >= 7.1.',
            [
                new VersionSpecificCodeSample(
                    "<?php\nfunction foo(\$a) {};",
                    new VersionSpecification(70100)
                ),
            ],
            'Rule is applied only in a PHP 7.1+ environment.',
            'Modifies the signature of functions.'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getPriority()
    {
        // must run before ReturnTypeDeclarationFixer and PhpdocNoEmptyReturnFixer
        return 15;
    }

    /**
     * {@inheritdoc}
     */
    public function isCandidate(Tokens $tokens)
    {
        return PHP_VERSION_ID >= 70100 && $tokens->isTokenKindFound(T_FUNCTION);
    }

    /**
     * {@inheritdoc}
     */
    public function isRisky()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function applyFix(\SplFileInfo $file, Tokens $tokens)
    {
        // These cause syntax errors.
        static $blacklistFuncNames = [
            [T_STRING, '__construct'],
            [T_STRING, '__destruct'],
            [T_STRING, '__clone'],
        ];

        for ($index = $tokens->count() - 1; 0 <= $index; --$index) {
            if (!$tokens[$index]->isGivenKind(T_FUNCTION)) {
                continue;
            }

            $funcName = $tokens->getNextMeaningfulToken($index);
            if ($tokens[$funcName]->equalsAny($blacklistFuncNames, false)) {
                continue;
            }

            $startIndex = $tokens->getNextTokenOfKind($index, ['{', ';']);

            if ($this->hasReturnTypeHint($tokens, $startIndex)) {
                continue;
            }

            if (!$tokens[$startIndex]->equals('{')) {
                // No function body defined, fallback to PHPDoc.
                if ($this->hasVoidReturnAnnotation($tokens, $index)) {
                    $this->fixFunctionDefinition($tokens, $startIndex);
                }

                continue;
            }

            if ($this->hasReturnAnnotation($tokens, $index)) {
                continue;
            }

            $endIndex = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_CURLY_BRACE, $startIndex);

            if ($this->hasVoidReturn($tokens, $startIndex, $endIndex)) {
                $this->fixFunctionDefinition($tokens, $startIndex);
            }
        }
    }

    /**
     * Determine if there is a non-void return annotation in the function's PHPDoc comment.
     *
     * @param Tokens $tokens
     * @param int    $index  The index of the function token
     *
     * @return bool
     */
    private function hasReturnAnnotation(Tokens $tokens, $index)
    {
        foreach ($this->findReturnAnnotations($tokens, $index) as $return) {
            if (0 === preg_match(self::VOID_RETURN_PATTERN, $return->getContent())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if there is a void return annotation in the function's PHPDoc comment.
     *
     * @param Tokens $tokens
     * @param int    $index  The index of the function token
     *
     * @return bool
     */
    private function hasVoidReturnAnnotation(Tokens $tokens, $index)
    {
        foreach ($this->findReturnAnnotations($tokens, $index) as $return) {
            if (1 === preg_match(self::VOID_RETURN_PATTERN, $return->getContent())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine the function already has a return type hint.
     *
     * @param Tokens $tokens
     * @param int    $index  The index of the end of the function definition line, EG at { or ;
     *
     * @return bool
     */
    private function hasReturnTypeHint(Tokens $tokens, $index)
    {
        $endFuncIndex = $tokens->getPrevTokenOfKind($index, [')']);
        $nextIndex = $tokens->getNextMeaningfulToken($endFuncIndex);

        return $tokens[$nextIndex]->equals([CT::T_TYPE_COLON, ':']);
    }

    /**
     * Determine if the function has a void return.
     *
     * @param Tokens $tokens
     * @param int    $startIndex Start of function body
     * @param int    $endIndex   End of function body
     *
     * @return bool
     */
    private function hasVoidReturn(Tokens $tokens, $startIndex, $endIndex)
    {
        for ($i = $startIndex; $i < $endIndex; ++$i) {
            if ($tokens[$i]->isGivenKind(T_YIELD)) {
                return false; // Do not apply fix as generators cannot return void.
            }
            if (!$tokens[$i]->isGivenKind(T_RETURN)) {
                continue;
            }

            $nextToken = $tokens->getNextMeaningfulToken($i);
            if (!$tokens[$nextToken]->equals(';')) {
                return false; // Do not apply fix, non-empty return statement found.
            }
        }

        return true;
    }

    /**
     * Apply the fix to the function definition.
     *
     * @param Tokens $tokens
     * @param int    $index  The index of the end of the function definition line, EG at { or ;
     */
    private function fixFunctionDefinition(Tokens $tokens, $index)
    {
        $endFuncIndex = $tokens->getPrevTokenOfKind($index, [')']);
        $tokens->insertAt($endFuncIndex + 1, [
            new Token([CT::T_TYPE_COLON, ':']),
            new Token([T_WHITESPACE, ' ']),
            new Token([T_STRING, 'void']),
        ]);
    }

    /**
     * Find all the return annotations in the function's PHPDoc comment.
     *
     * @param Tokens $tokens
     * @param int    $index  The index of the function token
     *
     * @return Annotation[]
     */
    private function findReturnAnnotations(Tokens $tokens, $index)
    {
        do {
            $index = $tokens->getPrevNonWhitespace($index);
        } while ($tokens[$index]->isGivenKind([
            T_ABSTRACT,
            T_FINAL,
            T_PRIVATE,
            T_PROTECTED,
            T_PUBLIC,
            T_STATIC,
        ]));

        if (!$tokens[$index]->isGivenKind(T_DOC_COMMENT)) {
            return [];
        }

        $doc = new DocBlock($tokens[$index]->getContent());

        return $doc->getAnnotationsOfType('return');
    }
}
