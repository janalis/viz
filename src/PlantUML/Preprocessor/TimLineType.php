<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Preprocessor;

/** What a line is to PlantUML's preprocessor, as its `TLineType` tells it from its start. */
enum TimLineType
{
    private const string IDENTIFIER = '[\p{L}_][\p{L}_0-9]*';

    case Plain;
    case AffectationDefine;
    case Affectation;
    case Assert;
    case If;
    case Ifdef;
    case Undef;
    case Ifndef;
    case Else;
    case Elseif;
    case Endif;
    case While;
    case Endwhile;
    case Foreach;
    case Endforeach;
    case DeclareReturnFunction;
    case DeclareProcedure;
    case EndFunction;
    case Return;
    case LegacyDefine;
    case LegacyDefinelong;
    case Theme;
    case Include;
    case IncludeDef;
    case Import;
    case Startsub;
    case Endsub;
    case Includesub;
    case Log;
    case DumpMemory;
    case CommentSimple;
    case CommentLongStart;
    case Option;

    public static function of(string $line): self
    {
        if (1 === \preg_match("~^\\s*'|^\\s*/'.*'/\\s*$~u", $line)) {
            return self::CommentSimple;
        }
        if (1 === \preg_match("~^\\s*/'~u", $line) && !\str_contains($line, "'/")) {
            return self::CommentLongStart;
        }
        if (!\str_contains($line, '!')) {
            return self::Plain;
        }
        foreach (self::patterns() as $pattern => $type) {
            if (1 === \preg_match('~^\s*'.$pattern.'~u', $line)) {
                return $type;
            }
        }

        return self::Plain;
    }

    /** @return array<string, self> in the order PlantUML tries them */
    private static function patterns(): array
    {
        $identifier = self::IDENTIFIER;

        return [
            '!define\s+'.$identifier.'\(' => self::LegacyDefine,
            '!definelong\s+'.$identifier.'\b' => self::LegacyDefinelong,
            '!define\s+'.$identifier.'\b' => self::AffectationDefine,
            '!\s*(?:local|global)?\s*\$?'.$identifier.'\s*\??=' => self::Affectation,
            '!ifdef\b' => self::Ifdef,
            '!undef\b' => self::Undef,
            '!ifndef\b' => self::Ifndef,
            '!assert\b' => self::Assert,
            '!if\b' => self::If,
            '!(?:unquoted\s|final\s)*function\s+\$?'.$identifier => self::DeclareReturnFunction,
            '!(?:unquoted\s|final\s)*procedure\s+\$?'.$identifier => self::DeclareProcedure,
            '!else\b' => self::Else,
            '!elseif\b' => self::Elseif,
            '!endif\b' => self::Endif,
            '!while\b' => self::While,
            '!endwhile\b' => self::Endwhile,
            '!foreach\b' => self::Foreach,
            '!endfor\b' => self::Endforeach,
            '!end\s*(?:function|definelong|procedure)\b' => self::EndFunction,
            '!return\b' => self::Return,
            '!theme\b' => self::Theme,
            '!include\s*(?:url|_many|_once)?\b' => self::Include,
            '!includedef\b' => self::IncludeDef,
            '!import\b' => self::Import,
            '!startsub\b' => self::Startsub,
            '!endsub\b' => self::Endsub,
            '!includesub\b' => self::Includesub,
            '!log\b' => self::Log,
            '!dump_memory\b' => self::DumpMemory,
            '!option\b' => self::Option,
        ];
    }
}
