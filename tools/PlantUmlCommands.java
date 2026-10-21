// Lists the commands of PlantUML's diagram factories, in the order PlantUML tries them, as
// JSON: each command's class, its starting pattern (and ending one, for a command over
// several lines) with PlantUML's %s, %g, %q and %pLN macros expanded, and the name of each
// capturing group of a starting pattern, as PlantUML's RegexResult names them.
// Run by tools/import-plantuml.php against the MIT jar:
//   javac -cp plantuml-mit.jar -d <dir> tools/PlantUmlCommands.java
//   java -cp plantuml-mit.jar:<dir> PlantUmlCommands <factory class>...

import java.lang.reflect.Constructor;
import java.lang.reflect.Field;
import java.lang.reflect.Method;
import java.lang.reflect.Modifier;
import java.util.ArrayList;
import java.util.List;

public class PlantUmlCommands {
    public static void main(String[] args) throws Exception {
        final StringBuilder json = new StringBuilder("{");
        for (int index = 0; index < args.length; index++) {
            json.append(index == 0 ? "" : ",").append("\n").append(quote(args[index])).append(":[");
            final List<Object> commands = commands(args[index]);
            for (int command = 0; command < commands.size(); command++)
                json.append(command == 0 ? "" : ",").append("\n").append(describe(commands.get(command)));
            json.append("]");
        }
        System.out.println(json.append("\n}"));
    }

    private static List<Object> commands(String factoryName) throws Exception {
        final Class<?> factoryClass = Class.forName(factoryName);
        Object factory = null;
        for (Constructor<?> constructor : factoryClass.getDeclaredConstructors()) {
            constructor.setAccessible(true);
            try {
                factory = constructor.newInstance(new Object[constructor.getParameterCount()]);
                break;
            } catch (Exception e) {
                // another constructor
            }
        }
        Method init = null;
        for (Class<?> type = factoryClass; type != null && init == null; type = type.getSuperclass())
            for (Method method : type.getDeclaredMethods())
                if (method.getName().equals("initCommandsList"))
                    init = method;
        init.setAccessible(true);
        final List<Object> commands = new ArrayList<>();
        init.invoke(factory, commands);
        return commands;
    }

    private static String describe(Object command) throws Exception {
        Object start = null;
        Object end = null;
        for (Class<?> type = command.getClass(); type != null && type != Object.class; type = type.getSuperclass()) {
            for (Field field : type.getDeclaredFields()) {
                if (Modifier.isStatic(field.getModifiers()))
                    continue;
                field.setAccessible(true);
                final Object value = lazy(field.get(command));
                if (value == null)
                    continue;
                final String name = field.getName();
                if (start == null && (name.equals("pattern") || name.equals("starting")))
                    start = value;
                else if (end == null && (name.equals("ending") || name.equals("end") || name.equals("patternEnd")))
                    end = value;
            }
        }
        final StringBuilder json = new StringBuilder("{\"class\":").append(quote(command.getClass().getName()));
        if (start != null && isUbrex(start)) {
            final List<String> slots = new ArrayList<>();
            final String regex = "^" + ubrex(challenge(start), slots, true) + "$";
            json.append(",\"start\":").append(quote(regex)).append(",\"slots\":[");
            for (int slot = 0; slot < slots.size(); slot++)
                json.append(slot == 0 ? "" : ",").append(quote(slots.get(slot)));
            // As Ubrex records them, only the groups that took something: a name's first is its first that matched.
            return json.append("],\"end\":null,\"ubrex\":true}").toString();
        }
        json.append(",\"start\":").append(start == null ? "null" : quote(transform(pattern(start))));
        json.append(",\"slots\":");
        if (start != null && start.getClass().getName().startsWith("net.sourceforge.plantuml.regex.Regex")) {
            final List<String> slots = new ArrayList<>();
            slots(start, slots);
            json.append("[");
            for (int slot = 0; slot < slots.size(); slot++)
                json.append(slot == 0 ? "" : ",").append(slots.get(slot) == null ? "null" : quote(slots.get(slot)));
            json.append("]");
        } else {
            json.append("null");
        }
        json.append(",\"end\":").append(end == null ? "null" : quote(transform(pattern(end))));
        return json.append("}").toString();
    }

    private static boolean isUbrex(Object pattern) {
        for (Class<?> type = pattern.getClass(); type != null; type = type.getSuperclass()) {
            if (type.getName().startsWith("com.plantuml.ubrex"))
                return true;
            for (Class<?> face : type.getInterfaces())
                if (face.getName().startsWith("com.plantuml.ubrex"))
                    return true;
        }
        return false;
    }

    /** The tree of challenges a Ubrex expression matches with. */
    private static Object challenge(Object pattern) throws Exception {
        try {
            final Method method = pattern.getClass().getMethod("getChallenge");
            method.setAccessible(true);
            return method.invoke(pattern);
        } catch (NoSuchMethodException e) {
            for (Field field : pattern.getClass().getDeclaredFields()) {
                field.setAccessible(true);
                final Object value = field.get(pattern);
                if (value != null && value.getClass().getName().startsWith("com.plantuml.ubrex"))
                    return value;
            }
            throw new IllegalStateException("No challenge in " + pattern.getClass());
        }
    }

    private static Object field(Object object, String name) throws Exception {
        for (Class<?> type = object.getClass(); type != null; type = type.getSuperclass()) {
            try {
                final Field field = type.getDeclaredField(name);
                field.setAccessible(true);
                return field.get(object);
            } catch (NoSuchFieldException e) {
                // a superclass's
            }
        }
        throw new IllegalStateException("No " + name + " in " + object.getClass());
    }

    /**
     * A Ubrex challenge as a PCRE pattern matching the same: Ubrex never backtracks, so its
     * repetitions are possessive and its alternatives atomic; each named group a capturing one,
     * its name added to the slots — none inside a lookahead.
     */
    private static String ubrex(Object challenge, List<String> slots, boolean capture) throws Exception {
        final String type = challenge.getClass().getSimpleName();
        switch (type) {
        case "CompositeList": {
            final StringBuilder result = new StringBuilder();
            for (Object child : (List<?>) field(challenge, "challenges"))
                result.append(ubrex(child, slots, capture));
            return result.toString();
        }
        case "CompositeNamed": {
            final StringBuilder result = new StringBuilder();
            for (Object child : (List<?>) field(challenge, "challenges"))
                result.append(ubrex(child, slots, capture));
            if (!capture)
                return "(?:" + result + ")";
            slots.add((String) field(challenge, "name"));
            return "(" + result + ")";
        }
        case "ChallengeSingleChar":
            return literal((Character) field(challenge, "ch"));
        case "ChallengeCharClass": {
            final Object charClass = field(challenge, "charClass");
            final String raw = classOf(String.valueOf(field(charClass, "charClassRaw")));
            final boolean negative = String.valueOf(field(charClass, "type")).equals("NEGATIVE");
            return negative ? "[^" + raw + "]" : "[" + raw + "]";
        }
        case "ChallengeCharSet": {
            final StringBuilder set = new StringBuilder();
            final Object chars = field(challenge, "charSet");
            final Method contains = chars.getClass().getMethod("contains", char.class);
            contains.setAccessible(true);
            for (char c = 32; c < 128; c++)
                if ((Boolean) contains.invoke(chars, c))
                    set.append(literal(c));
            for (Object raw : (java.util.Set<?>) field(challenge, "charClasses"))
                set.append(classOf(String.valueOf(raw)));
            return ((Boolean) field(challenge, "reversed") ? "[^" : "[") + set + "]";
        }
        case "ChallengeOneOrMore":
            return "(?:" + ubrex(field(challenge, "origin"), slots, capture) + ")++";
        case "ChallengeZeroOrMore":
            return "(?:" + ubrex(field(challenge, "origin"), slots, capture) + ")*+";
        case "ChallengeOptional":
            return "(?:" + ubrex(field(challenge, "origin"), slots, capture) + ")?+";
        case "ChallengeAlternative": {
            final StringBuilder result = new StringBuilder("(?>");
            boolean first = true;
            for (Object alternative : (java.util.Collection<?>) field(challenge, "alternatives")) {
                result.append(first ? "" : "|").append(ubrex(alternative, slots, capture));
                first = false;
            }
            return result.append(")").toString();
        }
        case "ChallengeLazzyOneOrMore":
            return "(?>(?:" + ubrex(field(challenge, "origin"), slots, capture) + ")+?(?=" + ubrex(field(challenge, "stopCondition"), slots, false) + "))";
        case "ChallengeOneOrMoreUpToOldVersion":
            return "(?>(?:" + ubrex(field(challenge, "origin"), slots, capture) + ")+?(?=" + ubrex(field(challenge, "end"), slots, false) + "))";
        case "ChallengeUpTo":
            return "(?>(?s:.)*?(?=" + ubrex(field(challenge, "origin"), slots, false) + "))";
        case "ChallengeRepetition": {
            // Ubrex takes every repeat, then checks their count: none never matches, nor more than the most.
            final Object repetition = field(challenge, "repetition");
            final java.util.TreeSet<?> values = (java.util.TreeSet<?>) field(repetition, "values");
            final int from = ((java.util.concurrent.atomic.AtomicInteger) field(repetition, "minInclusive")).get();
            final String origin = ubrex(field(challenge, "origin"), slots, capture);
            final int low = Math.max(1, values.isEmpty() ? from : (Integer) values.first());
            if (values.isEmpty() || (Integer) values.last() + 1 >= from && from != Integer.MAX_VALUE)
                return "(?:" + origin + "){" + low + ",}+";
            if ((Integer) values.last() - (Integer) values.first() + 1 != values.size() || from != Integer.MAX_VALUE)
                throw new IllegalStateException("Ubrex repetition not translated: " + values + " " + from);
            return "(?:" + origin + "){" + low + "," + values.last() + "}+(?!" + ubrex(field(challenge, "origin"), new ArrayList<>(), false) + ")";
        }
        case "ChallengeEndOfText":
            return "\\z";
        case "ChallengeLookAhead": {
            final boolean positive = String.valueOf(field(challenge, "ahead")).equals("LOOK_AHEAD_POSITIVE");
            return (positive ? "(?=" : "(?!") + ubrex(field(challenge, "origin"), slots, false) + ")";
        }
        default:
            throw new IllegalStateException("Ubrex challenge not translated: " + type);
        }
    }

    private static String classOf(String raw) {
        switch (raw) {
        case "ANY": return "\\s\\S";
        case "SPACE": return " ";
        case "GUILLEMET": return "\"\\x{201c}\\x{201d}";
        case "WORD": return "a-zA-Z0-9_";
        case "DIGIT": return "0-9_";
        case "ALPHA_NUMERIC": return "\\p{L}\\p{Nd}";
        case "LETTER": return "\\p{L}";
        default: throw new IllegalStateException("Ubrex class not translated: " + raw);
        }
    }

    private static String literal(char c) {
        if (Character.isLetterOrDigit(c) && c < 128)
            return String.valueOf(c);
        if (c < 128 && c > 32)
            return "\\" + c;
        if (c == ' ')
            return " ";
        return String.format("\\x{%04x}", (int) c);
    }

    private static Object lazy(Object value) throws Exception {
        if (value != null && value.getClass().getName().endsWith(".Lazy")) {
            final Method get = value.getClass().getDeclaredMethod("get");
            get.setAccessible(true);
            return get.invoke(value);
        }
        return value;
    }

    private static String pattern(Object regex) throws Exception {
        for (String name : new String[] { "getPatternAsString", "pattern" }) {
            try {
                final Method method = regex.getClass().getMethod(name);
                method.setAccessible(true);
                return String.valueOf(method.invoke(regex));
            } catch (NoSuchMethodException e) {
                // the other accessor
            }
        }
        return ((java.util.regex.Pattern) regex).pattern();
    }

    private static String transform(String pattern) throws Exception {
        final Class<?> pattern2 = Class.forName("net.sourceforge.plantuml.regex.Pattern2");
        return (String) pattern2.getMethod("transform", String.class).invoke(null, pattern);
    }

    /** The name of each capturing group, as RegexResult gathers them. */
    private static void slots(Object regex, List<String> slots) throws Exception {
        final String type = regex.getClass().getSimpleName();
        if (type.equals("RegexLeaf")) {
            final Method count = regex.getClass().getMethod("count");
            final String name = (String) regex.getClass().getMethod("getName").invoke(regex);
            for (int group = (Integer) count.invoke(regex); group > 0; group--)
                slots.add(name);
            return;
        }
        if (type.equals("RegexOr")) {
            final Field name = regex.getClass().getDeclaredField("name");
            name.setAccessible(true);
            if (name.get(regex) != null)
                slots.add((String) name.get(regex));
        }
        Class<?> composed = regex.getClass();
        while (!composed.getSimpleName().equals("RegexComposed"))
            composed = composed.getSuperclass();
        final Field partials = composed.getDeclaredField("partials");
        partials.setAccessible(true);
        for (Object partial : (List<?>) partials.get(regex))
            slots(partial, slots);
    }

    private static String quote(String text) {
        final StringBuilder json = new StringBuilder("\"");
        for (char c : text.toCharArray()) {
            if (c == '"' || c == '\\')
                json.append('\\').append(c);
            else if (c < 0x20 || c > 0x7e)
                json.append(String.format("\\u%04x", (int) c));
            else
                json.append(c);
        }
        return json.append("\"").toString();
    }
}
