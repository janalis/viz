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
