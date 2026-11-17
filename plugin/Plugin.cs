// VatTrackr Notify - vPilot plugin
//
// Forwards contact requests and other messages that arrive in vPilot to the
// VatTrackr Notify service, which relays them to the pilot's phone via Telegram.
//
// Written for C# 5 so it builds with the compiler that ships with Windows
// (see build.cmd). The vPilot events are attached by name at runtime, so the
// plugin does not depend on the exact vPilot version it was built against.
//
// The plugin sets itself up: without a key it asks the service for a setup
// session, opens the setup page in the browser if it is not open already, and
// stores the key once the pilot has connected Telegram there.

using System;
using System.Collections;
using System.Collections.Generic;
using System.Globalization;
using System.IO;
using System.Net;
using System.Reflection;
using System.Text;
using System.Threading;
using RossCarlson.Vatsim.Vpilot.Plugins;

[assembly: AssemblyTitle("VatTrackr Notify")]
[assembly: AssemblyDescription("Sends vPilot contact requests to your phone via Telegram")]
[assembly: AssemblyCompany("VatTrackr")]
[assembly: AssemblyProduct("VatTrackr Notify")]
[assembly: AssemblyVersion("1.1.0.0")]
[assembly: AssemblyFileVersion("1.1.0.0")]

namespace VatTrackrNotify
{
    public sealed class Plugin : IPlugin
    {
        public const string Version = "1.1.0";
        internal const string IniName = "VatTrackrNotify.ini";
        internal const string DefaultServer = "https://vattrackr.de/vatnotify/api.php";
        internal const string SetupUrl = "https://vattrackr.de/vatnotify/";

        private IBroker broker;
        private MethodInfo postDebug;
        private Settings settings;
        private Sender sender;
        private volatile string callsign;
        private readonly List<Delegate> handlers = new List<Delegate>();
        private readonly object pairGate = new object();
        private bool pairing;
        private bool rejectedBefore;

        public string Name
        {
            get { return "VatTrackr Notify"; }
        }

        public void Initialize(IBroker broker)
        {
            this.broker = broker;
            try
            {
                string home = Settings.Home();
                LogFile.Start(home);
                settings = Settings.Load(home);
                sender = new Sender(settings.Server, Debug, OnKeyRejected);

                Subscribe("NetworkConnected", "OnNetworkConnected");
                Subscribe("NetworkDisconnected", "OnNetworkDisconnected");
                if (settings.PrivateMessages) Subscribe("PrivateMessageReceived", "OnPrivateMessage");
                if (settings.RadioMessages) Subscribe("RadioMessageReceived", "OnRadioMessage");
                if (settings.Selcal) Subscribe("SelcalAlertReceived", "OnSelcal");

                Debug("Version " + Version + " loaded.");
                if (settings.Key.Length > 0)
                {
                    sender.SetKey(settings.Key);
                    sender.Enqueue("hello", null, null, null);
                }
                else
                {
                    StartSetup(settings.Pair);
                }
            }
            catch (Exception ex)
            {
                Debug("Could not start: " + ex.Message);
            }
        }

        // ------------------------------------------------------------ vPilot events
        // The handlers take plain objects so they fit every event signature vPilot uses.

        private void OnNetworkConnected(object source, object e)
        {
            try { callsign = Text(e, "Callsign"); }
            catch (Exception) { }
        }

        private void OnNetworkDisconnected(object source, object e)
        {
            try
            {
                callsign = null;
                if (settings.Disconnect) sender.Enqueue("disconnect", null, null, null);
            }
            catch (Exception) { }
        }

        private void OnPrivateMessage(object source, object e)
        {
            try
            {
                string message = Text(e, "Message");
                if (message.Length == 0) return;
                sender.Enqueue("private", Text(e, "From"), message, null);
            }
            catch (Exception) { }
        }

        private void OnRadioMessage(object source, object e)
        {
            try
            {
                // Only text that mentions our own callsign, not the whole frequency.
                string own = callsign;
                string message = Text(e, "Message");
                if (string.IsNullOrEmpty(own) || message.Length == 0) return;
                if (message.IndexOf(own, StringComparison.OrdinalIgnoreCase) < 0) return;
                sender.Enqueue("radio", Text(e, "From"), message, Frequency(Value(e, "Frequencies")));
            }
            catch (Exception) { }
        }

        private void OnSelcal(object source, object e)
        {
            try { sender.Enqueue("selcal", Text(e, "From"), null, Frequency(Value(e, "Frequencies"))); }
            catch (Exception) { }
        }

        // ------------------------------------------------------------ setup

        /// <summary>The service no longer knows our key: forget it and run the setup again (once per session).</summary>
        private void OnKeyRejected()
        {
            if (rejectedBefore)
            {
                Debug("The key is not accepted. Restart vPilot to run the setup again.");
                return;
            }
            rejectedBefore = true;
            Debug("The key is no longer valid. Starting the setup again.");
            settings.Key = "";
            settings.Pair = "";
            settings.Save();
            StartSetup(null);
        }

        private void StartSetup(string token)
        {
            lock (pairGate)
            {
                if (pairing) return;
                pairing = true;
            }
            Thread thread = new Thread(SetupLoop);
            thread.IsBackground = true;
            thread.Name = "VatTrackrNotifySetup";
            thread.Start(token);
        }

        /// <summary>
        /// Waits until the pilot has connected Telegram on the setup page, then stores the key.
        /// The token comes from the settings file (package from the setup page) or from the
        /// service. If the page is not open in a browser, the plugin opens it.
        /// </summary>
        private void SetupLoop(object state)
        {
            string token = state as string;
            bool opened = false;
            bool announced = false;
            int withoutPage = 0;
            DateTime started = DateTime.UtcNow;
            try
            {
                while ((DateTime.UtcNow - started).TotalMinutes < 60)
                {
                    bool fresh = string.IsNullOrEmpty(token);
                    string reply = fresh ? sender.Ask("device_start", null) : sender.Ask("device_poll", token);
                    if (reply == null)
                    {
                        Thread.Sleep(15000);
                        continue;
                    }
                    if (fresh)
                    {
                        token = Sender.Field(reply, "token");
                        if (token.Length == 0)
                        {
                            Thread.Sleep(15000);
                            continue;
                        }
                        settings.Pair = token;
                        settings.Save();
                    }

                    string status = Sender.Field(reply, "status");
                    if (status == "expired")
                    {
                        token = null;
                        continue;
                    }
                    if (status == "done")
                    {
                        string key = Sender.Field(reply, "key").ToUpperInvariant();
                        if (key.Length > 0)
                        {
                            settings.Key = key;
                            settings.Pair = "";
                            settings.Save();
                            sender.SetKey(key);
                            sender.Enqueue("hello", null, null, null);
                            Debug("Telegram is connected. Setup complete.");
                            return;
                        }
                    }

                    string page = Sender.Field(reply, "url");
                    if (!announced)
                    {
                        announced = true;
                        Debug("Waiting for the setup to be completed: " + page);
                    }
                    withoutPage = reply.IndexOf("\"page\":true", StringComparison.Ordinal) >= 0 ? 0 : withoutPage + 1;
                    if (!opened && withoutPage >= 3 && settings.OpenBrowser)
                    {
                        opened = true;
                        OpenBrowser(page);
                    }
                    Thread.Sleep((DateTime.UtcNow - started).TotalMinutes < 10 ? 3000 : 10000);
                }
                Debug("The setup was not completed. Restart vPilot to try again, or open " + SetupUrl);
            }
            catch (ThreadAbortException)
            {
                // vPilot is closing; nothing to report.
            }
            catch (Exception ex)
            {
                Debug("Setup failed: " + ex.Message);
            }
            finally
            {
                lock (pairGate) { pairing = false; }
            }
        }

        private void OpenBrowser(string url)
        {
            if (string.IsNullOrEmpty(url) || !Settings.Allowed(url)) return;
            try
            {
                System.Diagnostics.Process.Start(url);
                Debug("Opened the setup page in your browser.");
            }
            catch (Exception)
            {
                Debug("Could not open the browser. Please open this address yourself: " + url);
            }
        }

        // ------------------------------------------------------------ helpers

        private void Subscribe(string eventName, string handlerName)
        {
            EventInfo ev = FindEvent(eventName);
            if (ev == null)
            {
                Debug("This vPilot version has no event '" + eventName + "'.");
                return;
            }
            MethodInfo method = typeof(Plugin).GetMethod(handlerName, BindingFlags.Instance | BindingFlags.NonPublic);
            Delegate handler = Delegate.CreateDelegate(ev.EventHandlerType, this, method);
            ev.AddEventHandler(broker, handler);
            handlers.Add(handler);
        }

        private EventInfo FindEvent(string name)
        {
            EventInfo ev = typeof(IBroker).GetEvent(name);
            if (ev != null) return ev;
            foreach (Type contract in broker.GetType().GetInterfaces())
            {
                ev = contract.GetEvent(name);
                if (ev != null) return ev;
            }
            return broker.GetType().GetEvent(name);
        }

        private static object Value(object args, string property)
        {
            if (args == null) return null;
            PropertyInfo p = args.GetType().GetProperty(property);
            return p == null ? null : p.GetValue(args, null);
        }

        private static string Text(object args, string property)
        {
            object v = Value(args, property);
            return v == null ? "" : v.ToString().Trim();
        }

        /// <summary>vPilot reports 123.725 as 23725; turn the first entry back into "123.725".</summary>
        internal static string Frequency(object value)
        {
            int raw = 0;
            IEnumerable list = value as IEnumerable;
            if (list != null && !(value is string))
            {
                foreach (object item in list)
                {
                    try { raw = Convert.ToInt32(item, CultureInfo.InvariantCulture); }
                    catch (Exception) { raw = 0; }
                    break;
                }
            }
            if (raw <= 0) return "";
            if (raw >= 100000 && raw < 200000) raw -= 100000;
            if (raw >= 100000) return "";
            string digits = raw.ToString(CultureInfo.InvariantCulture).PadLeft(5, '0');
            return "1" + digits.Substring(0, 2) + "." + digits.Substring(2);
        }

        internal void Debug(string message)
        {
            LogFile.Write(message);
            try
            {
                if (postDebug == null)
                {
                    postDebug = typeof(IBroker).GetMethod("PostDebugMessage", new Type[] { typeof(string) });
                    if (postDebug == null) postDebug = broker.GetType().GetMethod("PostDebugMessage", new Type[] { typeof(string) });
                }
                if (postDebug != null) postDebug.Invoke(broker, new object[] { "VatTrackr Notify: " + message });
            }
            catch (Exception) { }
        }
    }

    // ---------------------------------------------------------------- settings

    internal sealed class Settings
    {
        public string Key = "";
        public string Pair = "";
        public string Server = Plugin.DefaultServer;
        public bool PrivateMessages = true;
        public bool RadioMessages = true;
        public bool Selcal = true;
        public bool Disconnect = true;
        public bool OpenBrowser = true;

        private string folder;
        private readonly object gate = new object();

        /// <summary>Reads the settings file; without one the plugin runs on defaults and sets itself up.</summary>
        public static Settings Load(string home)
        {
            foreach (string dir in Folders())
            {
                string file;
                try { file = Path.Combine(dir, Plugin.IniName); }
                catch (Exception) { continue; }
                if (File.Exists(file))
                {
                    Settings found = Parse(File.ReadAllLines(file, Encoding.UTF8));
                    found.folder = dir;
                    return found;
                }
            }
            Settings fresh = new Settings();
            fresh.folder = home;
            return fresh;
        }

        /// <summary>Writes Key and Pair back to the settings file and leaves everything else as it is.</summary>
        public void Save()
        {
            if (folder == null) return;
            lock (gate)
            {
                try
                {
                    string file = Path.Combine(folder, Plugin.IniName);
                    List<string> lines = new List<string>();
                    if (File.Exists(file))
                    {
                        lines.AddRange(File.ReadAllLines(file, Encoding.UTF8));
                    }
                    else
                    {
                        lines.Add("; VatTrackr Notify");
                        lines.Add("[VatTrackrNotify]");
                        lines.Add("Server=" + Server);
                    }
                    Put(lines, "Key", Key);
                    Put(lines, "Pair", Pair);
                    File.WriteAllLines(file, lines.ToArray(), new UTF8Encoding(false));
                }
                catch (Exception ex)
                {
                    LogFile.Write("Could not write " + Plugin.IniName + ": " + ex.Message);
                }
            }
        }

        private static void Put(List<string> lines, string name, string value)
        {
            string wanted = name.ToLowerInvariant();
            for (int i = 0; i < lines.Count; i++)
            {
                string line = lines[i].Trim();
                int eq = line.IndexOf('=');
                if (eq > 0 && line.Substring(0, eq).Trim().ToLowerInvariant() == wanted)
                {
                    lines[i] = name + "=" + value;
                    return;
                }
            }
            lines.Add(name + "=" + value);
        }

        /// <summary>The folder the plugin lives in: the first candidate that exists.</summary>
        internal static string Home()
        {
            foreach (string dir in Folders())
            {
                try { if (Directory.Exists(dir)) return dir; }
                catch (Exception) { }
            }
            return null;
        }

        private static IEnumerable<string> Folders()
        {
            List<string> dirs = new List<string>();
            try
            {
                string own = Assembly.GetExecutingAssembly().Location;
                if (!string.IsNullOrEmpty(own)) dirs.Add(Path.GetDirectoryName(own));
            }
            catch (Exception) { }
            try
            {
                using (Microsoft.Win32.RegistryKey key = Microsoft.Win32.Registry.CurrentUser.OpenSubKey(@"Software\vPilot"))
                {
                    string install = key == null ? null : key.GetValue("Install_Dir") as string;
                    if (!string.IsNullOrEmpty(install)) dirs.Add(Path.Combine(install, "Plugins"));
                }
            }
            catch (Exception) { }
            try
            {
                string local = Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData);
                if (!string.IsNullOrEmpty(local)) dirs.Add(Path.Combine(Path.Combine(local, "vPilot"), "Plugins"));
            }
            catch (Exception) { }
            return dirs;
        }

        internal static Settings Parse(string[] lines)
        {
            Settings s = new Settings();
            foreach (string rawLine in lines)
            {
                string line = rawLine.Trim();
                if (line.Length == 0 || line[0] == ';' || line[0] == '#' || line[0] == '[') continue;
                int eq = line.IndexOf('=');
                if (eq <= 0) continue;
                string name = line.Substring(0, eq).Trim().ToLowerInvariant();
                string value = line.Substring(eq + 1).Trim().Trim(new char[] { '"' });
                switch (name)
                {
                    case "key": s.Key = value.ToUpperInvariant(); break;
                    case "pair": s.Pair = value.ToLowerInvariant(); break;
                    case "server": if (Allowed(value)) s.Server = value; break;
                    case "privatemessages": s.PrivateMessages = Flag(value); break;
                    case "radiomessages": s.RadioMessages = Flag(value); break;
                    case "selcal": s.Selcal = Flag(value); break;
                    case "disconnect": s.Disconnect = Flag(value); break;
                    case "openbrowser": s.OpenBrowser = Flag(value); break;
                }
            }
            return s;
        }

        private static bool Flag(string value)
        {
            string v = value.ToLowerInvariant();
            return !(v == "0" || v == "false" || v == "no" || v == "off" || v == "nein" || v == "aus");
        }

        /// <summary>Messages only ever go out encrypted; plain http is accepted for local testing only.</summary>
        internal static bool Allowed(string url)
        {
            return url.StartsWith("https://", StringComparison.OrdinalIgnoreCase)
                || url.StartsWith("http://127.0.0.1", StringComparison.OrdinalIgnoreCase)
                || url.StartsWith("http://localhost", StringComparison.OrdinalIgnoreCase);
        }
    }

    // ---------------------------------------------------------------- log file

    /// <summary>
    /// A small text file next to the plugin that says what happened at start-up and why
    /// messages are not forwarded, if they are not. It is started fresh with every vPilot
    /// start and never contains message texts or the key.
    /// </summary>
    internal static class LogFile
    {
        private static readonly object gate = new object();
        private static string path;

        public static void Start(string folder)
        {
            try
            {
                if (folder == null) return;
                path = Path.Combine(folder, "VatTrackrNotify.log");
                File.WriteAllText(path, "");
            }
            catch (Exception) { path = null; }
        }

        public static void Write(string message)
        {
            if (path == null) return;
            try
            {
                string line = DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss", CultureInfo.InvariantCulture) + "  " + message + "\r\n";
                lock (gate) { File.AppendAllText(path, line); }
            }
            catch (Exception) { }
        }
    }

    // ---------------------------------------------------------------- delivery

    /// <summary>
    /// Sends messages on its own background thread so vPilot's window never waits for the network.
    /// </summary>
    internal sealed class Sender
    {
        private const int MaxQueued = 40;

        private string url;
        private volatile string key;
        private readonly Action<string> log;
        private readonly Action rejected;
        private readonly Queue<string> queue = new Queue<string>();
        private readonly object gate = new object();
        private bool connected;
        private DateTime pausedUntil = DateTime.MinValue;
        private DateTime lastNetworkLog = DateTime.MinValue;

        public Sender(string url, Action<string> log, Action rejected)
        {
            this.url = url;
            this.log = log;
            this.rejected = rejected;
            try
            {
                // Older .NET setups may not offer TLS 1.2 by default; leave "system default" (0) untouched.
                const SecurityProtocolType tls12 = (SecurityProtocolType)3072;
                if (ServicePointManager.SecurityProtocol != 0 && (ServicePointManager.SecurityProtocol & tls12) == 0)
                {
                    ServicePointManager.SecurityProtocol |= tls12;
                }
            }
            catch (Exception) { }

            Thread worker = new Thread(Run);
            worker.IsBackground = true;
            worker.Name = "VatTrackrNotify";
            worker.Start();
        }

        public void SetKey(string value)
        {
            key = value;
        }

        /// <summary>Queues a message for the pilot. Without a key (setup not finished) nothing is sent.</summary>
        public void Enqueue(string type, string from, string text, string freq)
        {
            string current = key;
            if (string.IsNullOrEmpty(current)) return;

            StringBuilder json = new StringBuilder(256);
            json.Append("{\"action\":\"notify\",\"key\":").Append(Quote(current));
            json.Append(",\"type\":").Append(Quote(type));
            if (!string.IsNullOrEmpty(from)) json.Append(",\"from\":").Append(Quote(from));
            if (!string.IsNullOrEmpty(text)) json.Append(",\"text\":").Append(Quote(text.Length > 700 ? text.Substring(0, 700) : text));
            if (!string.IsNullOrEmpty(freq)) json.Append(",\"freq\":").Append(Quote(freq));
            json.Append(",\"v\":").Append(Quote(Plugin.Version)).Append('}');

            lock (gate)
            {
                if (queue.Count >= MaxQueued) queue.Dequeue();
                queue.Enqueue(json.ToString());
                Monitor.Pulse(gate);
            }
        }

        /// <summary>Asks the service a setup question and returns its answer, or null if there was none.</summary>
        public string Ask(string action, string token)
        {
            StringBuilder json = new StringBuilder(128);
            json.Append("{\"action\":").Append(Quote(action));
            if (!string.IsNullOrEmpty(token)) json.Append(",\"token\":").Append(Quote(token));
            json.Append(",\"v\":").Append(Quote(Plugin.Version)).Append('}');

            int status;
            string body;
            if (Post(json.ToString(), out status, out body) && status == 200) return body;
            LogProblem(status > 0
                ? "The notification server answered HTTP " + status + " during setup."
                : "Could not reach the notification server for the setup.");
            return null;
        }

        private void Run()
        {
            while (true)
            {
                string json;
                lock (gate)
                {
                    while (queue.Count == 0) Monitor.Wait(gate);
                    json = queue.Dequeue();
                }
                try { Deliver(json); }
                catch (Exception) { }
            }
        }

        private void Deliver(string json)
        {
            if (DateTime.UtcNow < pausedUntil) return;

            int last = 0;
            for (int attempt = 1; attempt <= 3; attempt++)
            {
                int status;
                string body;
                if (Post(json, out status, out body))
                {
                    if (status == 200)
                    {
                        if (!connected)
                        {
                            connected = true;
                            log("Connected to the notification service.");
                        }
                        CheckForUpdate(body);
                        return;
                    }
                    if (status == 401 || status == 410)
                    {
                        // The service does not know this key (any more): stop sending and set up again.
                        key = null;
                        connected = false;
                        lock (gate) { queue.Clear(); }
                        rejected();
                        return;
                    }
                    if (status == 429)
                    {
                        pausedUntil = DateTime.UtcNow.AddSeconds(60);
                        log("Message limit reached, pausing for a minute.");
                        return;
                    }
                    if (status >= 400 && status < 500)
                    {
                        LogProblem("The notification server answered HTTP " + status + ". Messages are not forwarded.");
                        return;
                    }
                    last = status;
                }
                if (attempt < 3) Thread.Sleep(attempt * 3000);
            }

            LogProblem(last > 0
                ? "The notification server answered HTTP " + last + ". Messages are not forwarded right now."
                : "Could not reach the notification server. Messages are not forwarded right now.");
        }

        /// <summary>Reports a delivery problem in vPilot, at most once every five minutes.</summary>
        private void LogProblem(string message)
        {
            if ((DateTime.UtcNow - lastNetworkLog).TotalMinutes < 5) return;
            lastNetworkLog = DateTime.UtcNow;
            log(message);
        }

        private void CheckForUpdate(string body)
        {
            string latest = Field(body, "latest");
            if (latest.Length == 0 || latest == Plugin.Version) return;
            Version mine, theirs;
            if (System.Version.TryParse(Plugin.Version, out mine) && System.Version.TryParse(latest, out theirs) && theirs > mine)
            {
                log("Version " + latest + " is available: " + Plugin.SetupUrl);
            }
        }

        /// <summary>
        /// Returns false when the server could not be reached at all. Follows redirects itself
        /// (for example to or from "www") so the message is sent again as a POST instead of
        /// being dropped, and remembers the final address for the rest of the session.
        /// </summary>
        private bool Post(string json, out int status, out string body)
        {
            string target = url;
            status = 0;
            body = "";
            for (int hop = 0; hop < 4; hop++)
            {
                string location;
                if (!PostOnce(target, json, out status, out body, out location)) return false;
                bool redirect = status == 301 || status == 302 || status == 307 || status == 308;
                if (!redirect)
                {
                    if (status == 200) url = target;
                    return true;
                }
                Uri next;
                if (string.IsNullOrEmpty(location) || !Uri.TryCreate(new Uri(target), location, out next)) return true;
                if (!Settings.Allowed(next.AbsoluteUri)) return true;
                target = next.AbsoluteUri;
            }
            return true;
        }

        private static bool PostOnce(string target, string json, out int status, out string body, out string location)
        {
            status = 0;
            body = "";
            location = null;
            try
            {
                HttpWebRequest request = (HttpWebRequest)WebRequest.Create(target);
                request.AllowAutoRedirect = false;
                request.Method = "POST";
                request.ContentType = "application/json; charset=utf-8";
                request.UserAgent = "VatTrackrNotify/" + Plugin.Version;
                request.Timeout = 10000;
                request.ReadWriteTimeout = 10000;
                request.ServicePoint.Expect100Continue = false;
                byte[] data = Encoding.UTF8.GetBytes(json);
                request.ContentLength = data.Length;
                using (Stream stream = request.GetRequestStream())
                {
                    stream.Write(data, 0, data.Length);
                }
                try
                {
                    using (HttpWebResponse response = (HttpWebResponse)request.GetResponse())
                    {
                        status = (int)response.StatusCode;
                        location = response.Headers["Location"];
                        body = ReadAll(response);
                    }
                }
                catch (WebException ex)
                {
                    HttpWebResponse response = ex.Response as HttpWebResponse;
                    if (response == null) return false;
                    using (response)
                    {
                        status = (int)response.StatusCode;
                        location = response.Headers["Location"];
                        body = ReadAll(response);
                    }
                }
                return true;
            }
            catch (Exception)
            {
                return false;
            }
        }

        private static string ReadAll(HttpWebResponse response)
        {
            try
            {
                using (Stream stream = response.GetResponseStream())
                {
                    if (stream == null) return "";
                    using (StreamReader reader = new StreamReader(stream, Encoding.UTF8))
                    {
                        char[] buffer = new char[4096];
                        int n = reader.Read(buffer, 0, buffer.Length);
                        return n > 0 ? new string(buffer, 0, n) : "";
                    }
                }
            }
            catch (Exception)
            {
                return "";
            }
        }

        /// <summary>Reads a simple string value like "latest":"1.0.1" from a small JSON reply.</summary>
        internal static string Field(string json, string name)
        {
            string marker = "\"" + name + "\":\"";
            int start = json.IndexOf(marker, StringComparison.Ordinal);
            if (start < 0) return "";
            start += marker.Length;
            int end = json.IndexOf('"', start);
            return end < 0 ? "" : json.Substring(start, end - start);
        }

        internal static string Quote(string value)
        {
            StringBuilder sb = new StringBuilder(value.Length + 2);
            sb.Append('"');
            foreach (char c in value)
            {
                switch (c)
                {
                    case '"': sb.Append("\\\""); break;
                    case '\\': sb.Append("\\\\"); break;
                    case '\n': sb.Append("\\n"); break;
                    case '\r': sb.Append("\\r"); break;
                    case '\t': sb.Append("\\t"); break;
                    default:
                        if (c < ' ')
                        {
                            sb.Append("\\u").Append(((int)c).ToString("x4", CultureInfo.InvariantCulture));
                        }
                        else
                        {
                            sb.Append(c);
                        }
                        break;
                }
            }
            sb.Append('"');
            return sb.ToString();
        }
    }
}
