// Exports libraries of PlantUML's standard library from its MIT jar, which packs them in
// Brotli-compressed .spm files: each library's info, its .puml files and its gray sprites, as
// one JSON document on standard output — with --info, each library's info alone.
// tools/import-plantuml.php compiles and runs it:
//   java -cp <plantuml-mit jar>:<classes> PlantUmlStdlib [--info] <library>…
import java.io.DataInputStream;
import java.io.IOException;
import java.io.InputStream;
import java.nio.charset.StandardCharsets;

import net.sourceforge.plantuml.preproc.spm.SpmChannel;

public class PlantUmlStdlib {

	public static void main(String[] args) throws IOException {
		final boolean infoOnly = args.length > 0 && args[0].equals("--info");
		final StringBuilder json = new StringBuilder("{");
		for (int i = infoOnly ? 1 : 0; i < args.length; i++) {
			if (json.length() > 1)
				json.append(',');
			json.append(quoted(args[i])).append(":{\"info\":").append(quoted(info(args[i])));
			if (infoOnly == false) {
				json.append(",\"puml\":{").append(files(args[i])).append("}");
				json.append(",\"sprites\":{").append(sprites(args[i])).append("}");
			}
			json.append('}');
		}
		System.out.print(json.append('}'));
	}

	private static String info(String lib) throws IOException {
		try (InputStream is = SpmChannel.INFO.getInternalInputStream(lib)) {
			return new String(is.readAllBytes(), StandardCharsets.UTF_8);
		}
	}

	private static String files(String lib) throws IOException {
		final StringBuilder json = new StringBuilder();
		try (DataInputStream dis = new DataInputStream(SpmChannel.PUML.getInternalInputStream(lib))) {
			final int count = dis.readInt();
			for (int i = 0; i < count; i++) {
				final String name = dis.readUTF();
				final byte[] data = dis.readNBytes(dis.readInt());
				if (i > 0)
					json.append(',');
				json.append(quoted(name)).append(':').append(quoted(new String(data, StandardCharsets.UTF_8)));
			}
		}
		return json.toString();
	}

	// Each sprite as rows of hex digits, 16 gray levels, as a sprite { } block writes them.
	private static String sprites(String lib) throws IOException {
		final StringBuilder json = new StringBuilder();
		try (DataInputStream dis = new DataInputStream(SpmChannel.SPRITE.getInternalInputStream(lib))) {
			final int count = dis.readInt();
			for (int s = 0; s < count; s++) {
				final String name = dis.readUTF();
				final int width = dis.readInt();
				final int height = dis.readInt();
				final byte[] data = dis.readNBytes(width * ((height + 1) / 2));
				final char[][] rows = new char[height][width];
				for (int j = 0; j < (height + 1) / 2; j++)
					for (int i = 0; i < width; i++) {
						final int b = data[j * width + i];
						rows[j * 2][i] = Character.forDigit((b & 0xF0) >> 4, 16);
						if (j * 2 + 1 < height)
							rows[j * 2 + 1][i] = Character.forDigit(b & 0x0F, 16);
					}
				if (s > 0)
					json.append(',');
				json.append(quoted(name)).append(":[");
				for (int j = 0; j < height; j++)
					json.append(j > 0 ? "," : "").append(quoted(new String(rows[j])));
				json.append(']');
			}
		} catch (IOException e) {
			// A library without sprites has no sprite channel.
		}
		return json.toString();
	}

	private static String quoted(String s) {
		final StringBuilder sb = new StringBuilder("\"");
		for (char c : s.toCharArray()) {
			if (c == '"' || c == '\\')
				sb.append('\\').append(c);
			else if (c < 0x20)
				sb.append(String.format("\\u%04x", (int) c));
			else
				sb.append(c);
		}
		return sb.append('"').toString();
	}
}
