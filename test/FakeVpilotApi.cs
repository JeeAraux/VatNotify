// Nachbau der vPilot-Plugin-Schnittstelle fuer Build und Test.
// Typen, Ereignisse und Eigenschaften folgen der offiziellen XML-Dokumentation
// (RossCarlson.Vatsim.Vpilot.Plugins.xml). Diese Datei wird NICHT ausgeliefert:
// im echten vPilot stellt vPilot selbst die Schnittstelle bereit.
//
// Mit -define:VARIANT entsteht eine zweite Fassung mit bewusst anderen
// Ereignis-Signaturen, um zu pruefen, dass das Plugin davon unabhaengig ist.

using System;
using System.Reflection;

[assembly: AssemblyVersion("3.9.0.0")]

namespace RossCarlson.Vatsim.Vpilot.Plugins
{
    public interface IPlugin
    {
        string Name { get; }
        void Initialize(IBroker broker);
    }

    public interface IBroker
    {
        event EventHandler SessionEnded;
        event EventHandler<Events.NetworkConnectedEventArgs> NetworkConnected;
#if VARIANT
        event EventHandler<EventArgs> NetworkDisconnected;
#else
        event EventHandler NetworkDisconnected;
#endif
        event EventHandler<Events.PrivateMessageReceivedEventArgs> PrivateMessageReceived;
        event EventHandler<Events.RadioMessageReceivedEventArgs> RadioMessageReceived;
        event EventHandler<Events.BroadcastMessageReceivedEventArgs> BroadcastMessageReceived;
        event EventHandler<Events.SelcalAlertReceivedEventArgs> SelcalAlertReceived;

        void RequestConnect(string callsign, string typeCode, string selcalCode);
        void RequestDisconnect();
        void RequestMetar(string station);
        void RequestAtis(string callsign);
        void SendPrivateMessage(string to, string message);
        void SendRadioMessage(string message);
        void RequestFlightPlan();
        void PostDebugMessage(string message);
        void SetModeC(bool modeC);
        void SquawkIdent();
        void SetPtt(bool pressed);
    }
}

namespace RossCarlson.Vatsim.Vpilot.Plugins.Events
{
#if VARIANT
    public class ArgsBase { }
#else
    public class ArgsBase : EventArgs { }
#endif

    public class NetworkConnectedEventArgs : ArgsBase
    {
        public string Callsign { get; private set; }
        public string TypeCode { get; private set; }
        public string SelcalCode { get; private set; }
        public bool ObserverMode { get; private set; }
        public NetworkConnectedEventArgs(string callsign, string typeCode, string selcalCode, bool observerMode)
        {
            Callsign = callsign; TypeCode = typeCode; SelcalCode = selcalCode; ObserverMode = observerMode;
        }
    }

    public class PrivateMessageReceivedEventArgs : ArgsBase
    {
        public string From { get; private set; }
        public string Message { get; private set; }
        public PrivateMessageReceivedEventArgs(string from, string message) { From = from; Message = message; }
    }

    public class RadioMessageReceivedEventArgs : ArgsBase
    {
        public int[] Frequencies { get; private set; }
        public string From { get; private set; }
        public string Message { get; private set; }
        public RadioMessageReceivedEventArgs(int[] frequencies, string from, string message)
        {
            Frequencies = frequencies; From = from; Message = message;
        }
    }

    public class BroadcastMessageReceivedEventArgs : ArgsBase
    {
        public string From { get; private set; }
        public string Message { get; private set; }
        public BroadcastMessageReceivedEventArgs(string from, string message) { From = from; Message = message; }
    }

    public class SelcalAlertReceivedEventArgs : ArgsBase
    {
        public int[] Frequencies { get; private set; }
        public string From { get; private set; }
        public SelcalAlertReceivedEventArgs(int[] frequencies, string from) { Frequencies = frequencies; From = from; }
    }
}
