<?php

declare(strict_types=1);

namespace Janalis\Viz\Mermaid;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Yaml;

/** Participant YAML/JSON maps, extracting only the two fields the pinned database consumes. */
final class SequenceConfiguration
{
    /** @return array{string, ?string, ?string} participant id, alias and decorative type */
    public static function parse(string $text, MermaidStatement $statement, ?string $description = null): array
    {
        $parts = \explode('@', $text, limit: 2);
        if (1 === \count($parts)) {
            return [\trim($text), null, null];
        }
        try {
            // @mago-expect analysis:mixed-assignment -- JSON is narrowed to an object below.
            $object = \json_decode(\trim($parts[1]), associative: false, depth: 128, flags: \JSON_THROW_ON_ERROR);
            SequenceYamlFlowKeys::normalize(\trim($parts[1]), $statement);
        } catch (\JsonException) {
            // @mago-expect analysis:mixed-assignment -- YAML is narrowed to a map object below.
            $object = self::yaml(\trim($parts[1]), $statement);
        }
        if (!$object instanceof \stdClass) {
            $configuration = \trim($parts[1]);
            if (!\str_starts_with($configuration, '{') || !\str_ends_with($configuration, '}')) {
                throw $statement->error('Sequence participant configuration must be an object');
            }
            $object = new \stdClass();
        }
        $fields = [];
        // @mago-expect analysis:mixed-assignment -- Each decoded field is narrowed to a string below.
        foreach (\get_object_vars($object) as $key => $value) {
            if (!\in_array($key, ['type', 'alias'], strict: true)) {
                continue;
            }
            // The pinned database only consults aliases when the raw description permits it.
            if ('alias' === $key && !SequenceText::usesAlias(\trim($parts[0]), $description)) {
                continue;
            }
            if (('alias' === $key && self::absentAlias($value)) || ('type' === $key && null === $value)) {
                continue;
            }
            if (!\is_string($value)) {
                throw $statement->error('Unsupported sequence participant configuration field');
            }
            $fields[$key] = $value;
        }
        $alias = $fields['alias'] ?? null;
        // Mermaid ignores an empty configured alias; "0" remains a valid string alias.
        if ('' === $alias) {
            $alias = null;
        }
        $type = $fields['type'] ?? null;
        if (null !== $type && !\in_array($type, ['participant', 'actor', 'boundary', 'control', 'entity', 'database', 'collections', 'queue'], strict: true)) {
            throw $statement->error('Unsupported sequence participant type');
        }

        return [\trim($parts[0]), null === $alias ? null : MermaidText::decode($alias), $type];
    }

    /** JavaScript false values omit an alias; the string "0" remains a visible label. */
    private static function absentAlias(mixed $value): bool
    {
        return null === $value || false === $value || 0 === $value || 0.0 === $value || (\is_float($value) && \is_nan($value));
    }

    private static function yaml(string $text, MermaidStatement $statement): mixed
    {
        if (!\mb_check_encoding($text, encoding: 'UTF-8') || !\str_starts_with($text, '{') || !\str_ends_with($text, '}')) {
            throw $statement->error('Invalid sequence participant YAML configuration');
        }
        $body = \substr($text, offset: 1, length: -1);
        // The pinned database treats multiline metadata as a block map. Keep the
        // existing JSON fast path above so authored multiline JSON stays supported.
        $yaml = "{\n".$body."\n}";
        if (\str_contains($body, "\n")) {
            $yaml = $body."\n";
        }
        try {
            $yaml = SequenceYamlSchema::normalize($yaml, $statement);
            [$yaml, $generated] = SequenceYamlBlockKeys::normalize($yaml, $statement);
            [$yaml, $generated] = SequenceYamlComplexKeys::normalize($yaml, $statement, $generated);
            if (null !== $generated) {
                $yaml = SequenceYamlSchema::normalize($yaml, $statement);
            }
            $yaml = SequenceYamlReferences::project($yaml, $statement, $generated);
            $yaml = SequenceYamlBlockScalarKeys::normalize($yaml, $statement);
            $yaml = SequenceYamlFlowKeys::normalize($yaml, $statement);
            $yaml = SequenceYamlIndentation::block($yaml, $statement);
            if (null !== $generated) {
                $yaml = SequenceYamlFlowLines::normalize($yaml, $statement);
            }
            [$yaml, $marker] = SequenceYamlMerges::mask($yaml);
            // Scalar references retain their values. Collection references are
            // projected without traversal; the original definitions stay validated.
            // @mago-expect analysis:mixed-assignment -- The parsed document is narrowed to an object below.
            $object = self::document(MermaidYamlScalars::preserveDates($yaml));
            if ($object instanceof \stdClass && null !== $marker) {
                foreach (['alias', 'type'] as $key) {
                    // @mago-expect analysis:mixed-assignment -- Only string fields need restoration.
                    $value = $object->{$key} ?? null;
                    if (\is_string($value)) {
                        $object->{$key} = \str_replace($marker, replace: '<<', subject: $value);
                    }
                }
            }

            return $object;
        } catch (ParseException) {
            throw $statement->error('Invalid sequence participant YAML configuration');
        }
    }

    /**
     * Parses a document, refusing an alias that would expand a collection. A document that is a
     * block scalar alone is read as a key's value, its lines moved in under the key, as every
     * symfony/yaml 8 reads it: only 8.2 reads one at the root.
     */
    private static function document(string $yaml): mixed
    {
        $parser = new Parser(maxAliasesForCollections: 0);
        $flags = Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE;
        $header = [];
        if (1 !== \preg_match('/\A([|>])(?:([1-9])([-+]?)|([-+]?)([1-9]?))([ \t]+#[^\n]*)?\n/', $yaml, $header)) {
            return $parser->parse($yaml, flags: $flags);
        }
        // Groups left unmatched at the end are not set. An indentation indicator counts from the key now, two in from where the document began.
        $header = \array_pad($header, length: 7, value: '');
        $indent = $header[2].$header[5];
        $indicator = $header[1].('' === $indent ? '' : (string) ((int) $indent + 2)).$header[3].$header[4].$header[6];
        $body = (string) \preg_replace('/^(?=[^\n])/m', replacement: '  ', subject: \substr($yaml, \strlen($header[0])));
        // @mago-expect analysis:mixed-assignment -- The wrapping map holds the scalar alone.
        $wrapped = $parser->parse('value: '.$indicator."\n".$body, flags: $flags);

        return $wrapped instanceof \stdClass ? $wrapped->value : null;
    }
}
