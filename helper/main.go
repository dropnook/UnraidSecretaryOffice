// uso-net — the Unraid Secretary Office's own node on a tailnet (the USO network).
//
// One tsnet node whose network device is born inside a network namespace of the office's own (`uso-net`): its UDP
// and control traffic leave through the host's normal way out, while every packet to or from the tailnet goes
// through the TUN device in that namespace. The office's sshd listens there on the node's addresses and sees each
// partner's real tailnet address, so the pairing's door line (`from="100.x.y.z"`) works as written. Nothing of the
// host's network changes: no device, address, route, DNS or firewall rule outside the namespace.
//
//	uso-net up -state DIR -netns NAME -status FILE [-hostname H] [-port P] [-control URL] [-authkey-file F]
//	uso-net logout -state DIR [-hostname H] [-control URL]   the node leaves its tailnet (the office's «Leave»)
//	uso-net version
//
// Built with ts_omit_logtail: nothing is uploaded and no log is written into the state folder (it lies on the
// flash); the state itself is written only when the node logs in. The status file (RAM) is the office's only view.
package main

import (
	"context"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"log"
	"net/netip"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"runtime"
	"slices"
	"sort"
	"strings"
	"syscall"
	"time"

	"github.com/tailscale/wireguard-go/tun"
	"golang.org/x/sys/unix"
	"tailscale.com/tsnet"
)

// Version is the helper's own version: the office pins this binary by URL and SHA-256 (agent/lib/usonet.php).
const Version = "1"

const (
	ifName      = "uso0"
	mtu         = 1280
	statusEvery = 10 * time.Second
	statusAgain = 30 * time.Second // written at least this often, so its age says the helper is alive
)

func main() {
	log.SetFlags(log.LstdFlags | log.LUTC)
	if len(os.Args) < 2 {
		usage()
	}
	switch os.Args[1] {
	case "up":
		up(os.Args[2:])
	case "logout":
		logout(os.Args[2:])
	case "version":
		fmt.Println(Version)
	default:
		usage()
	}
}

func usage() {
	fmt.Fprintln(os.Stderr, "usage: uso-net up|logout|version [flags]")
	os.Exit(2)
}

// tunInNetns opens the TUN device while this thread is inside the namespace, so the device is born there; the
// thread goes back to the host's namespace before it is handed back to Go's scheduler.
func tunInNetns(ns string) (tun.Device, error) {
	runtime.LockOSThread()
	defer runtime.UnlockOSThread()
	host, err := os.Open(fmt.Sprintf("/proc/self/task/%d/ns/net", unix.Gettid()))
	if err != nil {
		return nil, err
	}
	defer host.Close()
	target, err := os.Open(filepath.Join("/run/netns", ns))
	if err != nil {
		return nil, err
	}
	defer target.Close()
	if err := unix.Setns(int(target.Fd()), unix.CLONE_NEWNET); err != nil {
		return nil, fmt.Errorf("setns into %s: %w", ns, err)
	}
	dev, tunErr := tun.CreateTUN(ifName, mtu)
	if err := unix.Setns(int(host.Fd()), unix.CLONE_NEWNET); err != nil {
		log.Fatalf("back into the host's namespace: %v", err) // this thread must never run anything else now
	}
	return dev, tunErr
}

// ipIn runs `ip -n <ns> …` (the namespace's own addresses and routes only).
func ipIn(ns string, args ...string) error {
	out, err := exec.Command("ip", append([]string{"-n", ns}, args...)...).CombinedOutput()
	if err != nil {
		return fmt.Errorf("ip -n %s %s: %v: %s", ns, strings.Join(args, " "), err, strings.TrimSpace(string(out)))
	}
	return nil
}

// configure gives the namespace's device the node's addresses and the tailnet's routes — nothing else is routed there.
func configure(ns string, ips []netip.Addr) error {
	steps := [][]string{{"link", "set", "lo", "up"}, {"link", "set", ifName, "up"}, {"addr", "flush", "dev", ifName, "scope", "global"}}
	for _, a := range ips {
		bits := 32
		if a.Is6() {
			bits = 128
		}
		steps = append(steps, []string{"addr", "replace", netip.PrefixFrom(a, bits).String(), "dev", ifName})
	}
	steps = append(steps, []string{"route", "replace", "100.64.0.0/10", "dev", ifName},
		[]string{"-6", "route", "replace", "fd7a:115c:a1e0::/48", "dev", ifName})
	for _, s := range steps {
		if err := ipIn(ns, s...); err != nil {
			return err
		}
	}
	return nil
}

type peer struct {
	Name     string   `json:"name"`
	IPs      []string `json:"ips"`
	Tags     []string `json:"tags"`
	Online   bool     `json:"online"`
	Direct   bool     `json:"direct"`
	Relay    string   `json:"relay"`
	LastSeen int64    `json:"last_seen"`
}

type status struct {
	V        int      `json:"v"`
	Time     int64    `json:"time"`
	Version  string   `json:"version"`
	Pid      int      `json:"pid"`
	Backend  string   `json:"backend"`
	Name     string   `json:"name"`
	IPs      []string `json:"ips"`
	Tags     []string `json:"tags"`
	Control  bool     `json:"control"`
	AuthURL  bool     `json:"auth_url"`
	Netns    bool     `json:"netns"`
	Peers    []peer   `json:"peers"`
}

func writeStatus(file string, s status) {
	b, _ := json.Marshal(s)
	tmp := file + ".tmp"
	if err := os.WriteFile(tmp, append(b, '\n'), 0o600); err == nil {
		os.Rename(tmp, file)
	}
}

func up(args []string) {
	fs := flag.NewFlagSet("up", flag.ExitOnError)
	state := fs.String("state", "", "state folder (the node's key)")
	ns := fs.String("netns", "uso-net", "network namespace for the device")
	statusFile := fs.String("status", "", "status file (RAM)")
	hostname := fs.String("hostname", "uso", "the node's name")
	port := fs.Uint("port", 41642, "UDP port")
	control := fs.String("control", "", "coordination server (empty: Tailscale's)")
	keyFile := fs.String("authkey-file", "", "file holding the auth key (only for the first login)")
	fs.Parse(args)
	if *state == "" || *statusFile == "" || *port > 65535 {
		log.Fatal("up: -state and -status are needed")
	}
	key := ""
	if *keyFile != "" {
		if b, err := os.ReadFile(*keyFile); err == nil {
			key = strings.TrimSpace(string(b))
		} else if !errors.Is(err, os.ErrNotExist) {
			log.Fatalf("auth key: %v", err)
		}
	}
	dev, err := tunInNetns(*ns)
	if err != nil {
		log.Fatalf("device in %s: %v", *ns, err)
	}
	s := &tsnet.Server{
		Dir:        *state,
		Hostname:   *hostname,
		ControlURL: *control,
		AuthKey:    key,
		Port:       uint16(*port),
		Tun:        dev,
		UserLogf:   func(string, ...any) {},
		Logf:       func(string, ...any) {},
	}
	if err := s.Start(); err != nil {
		log.Fatalf("start: %v", err)
	}
	log.Printf("uso-net %s started (namespace %s, UDP %d)", Version, *ns, *port)
	lc, err := s.LocalClient()
	if err != nil {
		log.Fatalf("local client: %v", err)
	}
	sig := make(chan os.Signal, 1)
	signal.Notify(sig, syscall.SIGTERM, syscall.SIGINT)
	ctx := context.Background()
	var have []netip.Addr
	var last status
	var lastWrite time.Time
	tick := time.NewTicker(statusEvery)
	defer tick.Stop()
	for {
		st := status{V: 1, Time: time.Now().Unix(), Version: Version, Pid: os.Getpid(), Backend: "Unknown", Peers: []peer{}, IPs: []string{}, Tags: []string{}}
		cctx, cancel := context.WithTimeout(ctx, 5*time.Second)
		full, err := lc.Status(cctx)
		cancel()
		if err == nil {
			st.Backend = full.BackendState
			st.AuthURL = full.AuthURL != ""
			ips := full.TailscaleIPs
			if !slices.Equal(ips, have) && len(ips) > 0 {
				if err := configure(*ns, ips); err != nil {
					log.Printf("namespace: %v", err)
				} else {
					log.Printf("addresses in %s: %v", *ns, ips)
					have = slices.Clone(ips)
				}
			}
			st.Netns = len(have) > 0 && slices.Equal(ips, have)
			for _, a := range ips {
				st.IPs = append(st.IPs, a.String())
			}
			if self := full.Self; self != nil {
				st.Name = self.HostName
				st.Control = self.Online
				if self.Tags != nil {
					st.Tags = self.Tags.AsSlice()
				}
			}
			for _, p := range full.Peer {
				q := peer{Name: p.HostName, Online: p.Online, Direct: p.CurAddr != "", Relay: p.Relay, IPs: []string{}, Tags: []string{}}
				for _, a := range p.TailscaleIPs {
					q.IPs = append(q.IPs, a.String())
				}
				if p.Tags != nil {
					q.Tags = p.Tags.AsSlice()
				}
				if !p.LastSeen.IsZero() {
					q.LastSeen = p.LastSeen.Unix()
				}
				st.Peers = append(st.Peers, q)
			}
			sort.Slice(st.Peers, func(i, j int) bool { return st.Peers[i].Name < st.Peers[j].Name })
		}
		changed := st.Backend != last.Backend || st.Control != last.Control || !slices.Equal(st.IPs, last.IPs)
		if changed {
			log.Printf("state %s, control %v", st.Backend, st.Control)
		}
		cmp := st
		cmp.Time, cmp.Pid = last.Time, last.Pid
		same, _ := json.Marshal(cmp)
		was, _ := json.Marshal(last)
		if string(same) != string(was) || time.Since(lastWrite) >= statusAgain {
			writeStatus(*statusFile, st)
			lastWrite = time.Now()
		}
		last = st
		select {
		case <-sig:
			log.Printf("stopping")
			os.Remove(*statusFile)
			s.Close()
			return
		case <-tick.C:
		}
	}
}

func logout(args []string) {
	fs := flag.NewFlagSet("logout", flag.ExitOnError)
	state := fs.String("state", "", "state folder")
	control := fs.String("control", "", "coordination server")
	hostname := fs.String("hostname", "uso", "the node's name (as at «up»: the coordination server sees it once more)")
	fs.Parse(args)
	if *state == "" {
		log.Fatal("logout: -state is needed")
	}
	s := &tsnet.Server{Dir: *state, ControlURL: *control, Hostname: *hostname, UserLogf: func(string, ...any) {}, Logf: func(string, ...any) {}}
	if err := s.Start(); err != nil {
		log.Fatalf("start: %v", err)
	}
	defer s.Close()
	lc, err := s.LocalClient()
	if err != nil {
		log.Fatalf("local client: %v", err)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	if err := lc.Logout(ctx); err != nil {
		log.Fatalf("logout: %v", err)
	}
	log.Printf("logged out")
}
