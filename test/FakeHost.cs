// Stellvertreter fuer vPilot: laedt alle Plugins aus einem Ordner, wie vPilot es tut,
// und spielt eine kleine Sitzung durch (verbinden, Nachrichten, SELCAL, trennen).
//
// Aufruf: mono FakeHost.exe <Plugins-Ordner> [nur-laden]

using System;
using System.IO;
using System.Reflection;
using System.Threading;
using RossCarlson.Vatsim.Vpilot.Plugins;
using RossCarlson.Vatsim.Vpilot.Plugins.Events;

class Broker : IBroker
{
    public event EventHandler SessionEnded;
    public event EventHandler<NetworkConnectedEventArgs> NetworkConnected;
#if VARIANT
    public event EventHandler<EventArgs> NetworkDisconnected;
#else
    public event EventHandler NetworkDisconnected;
#endif
    public event EventHandler<PrivateMessageReceivedEventArgs> PrivateMessageReceived;
    public event EventHandler<RadioMessageReceivedEventArgs> RadioMessageReceived;
    public event EventHandler<BroadcastMessageReceivedEventArgs> BroadcastMessageReceived;
    public event EventHandler<SelcalAlertReceivedEventArgs> SelcalAlertReceived;

    public void RequestConnect(string callsign, string typeCode, string selcalCode) { }
    public void RequestDisconnect() { }
    public void RequestMetar(string station) { }
    public void RequestAtis(string callsign) { }
    public void SendPrivateMessage(string to, string message) { }
    public void SendRadioMessage(string message) { }
    public void RequestFlightPlan() { }
    public void PostDebugMessage(string message) { Console.WriteLine("[debug] " + message); }
    public void SetModeC(bool modeC) { }
    public void SquawkIdent() { }
    public void SetPtt(bool pressed) { }

    public void Connect(string cs) { if (NetworkConnected != null) NetworkConnected(this, new NetworkConnectedEventArgs(cs, "A320", "ABCD", false)); }
    public void Disconnect() { if (NetworkDisconnected != null) NetworkDisconnected(this, EventArgs.Empty); }
    public void Private(string from, string msg) { if (PrivateMessageReceived != null) PrivateMessageReceived(this, new PrivateMessageReceivedEventArgs(from, msg)); }
    public void Radio(int freq, string from, string msg) { if (RadioMessageReceived != null) RadioMessageReceived(this, new RadioMessageReceivedEventArgs(new int[] { freq }, from, msg)); }
    public void Broadcast(string from, string msg) { if (BroadcastMessageReceived != null) BroadcastMessageReceived(this, new BroadcastMessageReceivedEventArgs(from, msg)); }
    public void Selcal(int freq, string from) { if (SelcalAlertReceived != null) SelcalAlertReceived(this, new SelcalAlertReceivedEventArgs(new int[] { freq }, from)); }
    public void End() { if (SessionEnded != null) SessionEnded(this, EventArgs.Empty); }
}

static class Program
{
    static int Main(string[] args)
    {
        string dir = args[0];
        // Zweites Argument: "nur-laden" (3 s laufen), "warten:N" (N s laufen), "sitzung:N" (N s warten, dann Sitzung)
        string mode = args.Length > 1 ? args[1] : "";
        bool loadOnly = mode == "nur-laden" || mode.StartsWith("warten:");
        int wait = mode.Contains(":") ? int.Parse(mode.Substring(mode.IndexOf(':') + 1)) : 0;
        Broker broker = new Broker();
        int loaded = 0;
        foreach (string dll in Directory.GetFiles(dir, "*.dll"))
        {
            Assembly asm = Assembly.LoadFrom(dll);
            foreach (Type t in asm.GetTypes())
            {
                if (t.IsAbstract || !typeof(IPlugin).IsAssignableFrom(t)) continue;
                IPlugin plugin = (IPlugin)Activator.CreateInstance(t);
                Console.WriteLine("[host] loading plugin: " + plugin.Name);
                plugin.Initialize(broker);
                loaded++;
            }
        }
        Console.WriteLine("[host] plugins loaded: " + loaded);
        if (loaded == 0) return 2;

        Thread.Sleep(1500);                      // hello geht raus
        if (loadOnly) { Thread.Sleep(wait > 0 ? wait * 1000 : 1500); return 0; }
        if (wait > 0) Thread.Sleep(wait * 1000); // Zeit fuer die Einrichtung

        broker.Radio(23725, "EDDM_TWR", "DLH123 before connect, must be ignored");
        broker.Connect("DLH123");
        broker.Private("EDMM_CTR", "Please contact me on 132.555");
        broker.Radio(23725, "EDDM_TWR", "dlh123 cleared to land runway 26R");
        broker.Radio(23725, "EDDM_TWR", "BAW77 line up and wait, must be ignored");
        broker.Broadcast("SERVER", "Broadcast, must be ignored");
        broker.Selcal(31800, "EGGX_FSS");
        broker.Private("AFR99", "Grüße – ça va? \"quoted\" back\\slash <tag> &amp; 😀\tTab");
        broker.Disconnect();
        broker.Radio(23725, "EDDM_TWR", "DLH123 after disconnect, must be ignored");

        Thread.Sleep(5000);                      // Warteschlange leeren lassen
        broker.End();
        return 0;
    }
}
