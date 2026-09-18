using System;
using System.IO;
using System.Runtime.InteropServices;
using System.ServiceProcess;
using System.Threading;
using System.Xml;

// Native SCM host. A kill-on-close Job Object owns PHP and all of its workers.
public sealed class NmsSshService : ServiceBase {
    IntPtr job=IntPtr.Zero, process=IntPtr.Zero;
    Timer watcher;
    [StructLayout(LayoutKind.Sequential)] struct STARTUPINFO { public int cb; public string reserved,desktop,title;public int x,y,xsize,ysize,xchars,ychars,fill,flags;public short show,reserved2;public IntPtr reservedPtr,input,output,error; }
    [StructLayout(LayoutKind.Sequential)] struct PROCESSINFO {public IntPtr process,thread;public int pid,tid;}
    [StructLayout(LayoutKind.Sequential)] struct BASICLIMIT {public long processTime,jobTime;public uint flags;public UIntPtr minWorking,maxWorking;public uint active;public UIntPtr affinity;public uint priority,scheduling;}
    [StructLayout(LayoutKind.Sequential)] struct IOCOUNTERS {public ulong readOps,writeOps,otherOps,readBytes,writeBytes,otherBytes;}
    [StructLayout(LayoutKind.Sequential)] struct LIMITS {public BASICLIMIT basic;public IOCOUNTERS io;public UIntPtr processMemory,jobMemory,peakProcess,peakJob;}
    [DllImport("kernel32.dll",CharSet=CharSet.Unicode,SetLastError=true)] static extern IntPtr CreateJobObject(IntPtr attributes,string name);
    [DllImport("kernel32.dll",SetLastError=true)] static extern bool SetInformationJobObject(IntPtr job,int info,IntPtr limits,uint length);
    [DllImport("kernel32.dll",SetLastError=true)] static extern bool AssignProcessToJobObject(IntPtr job,IntPtr process);
    [DllImport("kernel32.dll",CharSet=CharSet.Unicode,SetLastError=true)] static extern bool CreateProcess(string app,System.Text.StringBuilder command,IntPtr pa,IntPtr ta,bool inherit,uint flags,IntPtr env,string cwd,ref STARTUPINFO startup,out PROCESSINFO process);
    [DllImport("kernel32.dll")] static extern uint ResumeThread(IntPtr thread);
    [DllImport("kernel32.dll")] static extern bool CloseHandle(IntPtr handle);
    [DllImport("kernel32.dll")] static extern uint WaitForSingleObject(IntPtr handle,uint milliseconds);
    [DllImport("kernel32.dll")] static extern bool TerminateProcess(IntPtr handle,uint code);
    public NmsSshService(){ServiceName="nms-ssh";CanStop=true;AutoLog=false;}
    static string Quote(string value){if(value.IndexOf('"')>=0||value.IndexOf('\n')>=0||value.IndexOf('\r')>=0)throw new Exception("Invalid service path");return "\""+value+"\"";}
    protected override void OnStart(string[] args){
        var doc=new XmlDocument();doc.XmlResolver=null;
        doc.Load(Path.Combine(AppDomain.CurrentDomain.BaseDirectory,"service.xml"));
        string php=doc.SelectSingleNode("/service/php").InnerText, script=doc.SelectSingleNode("/service/script").InnerText, cwd=doc.SelectSingleNode("/service/cwd").InnerText;
        job=CreateJobObject(IntPtr.Zero,null);if(job==IntPtr.Zero)throw new Exception("Job creation failed");
        var limits=new LIMITS();limits.basic.flags=0x2000; // JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE
        int size=Marshal.SizeOf(limits);IntPtr block=Marshal.AllocHGlobal(size);
        try {Marshal.StructureToPtr(limits,block,false);if(!SetInformationJobObject(job,9,block,(uint)size))throw new Exception("Job policy failed");}finally{Marshal.FreeHGlobal(block);}
        var startup=new STARTUPINFO();startup.cb=Marshal.SizeOf(startup);PROCESSINFO child;
        if(!CreateProcess(php,new System.Text.StringBuilder(Quote(php)+" "+Quote(script)),IntPtr.Zero,IntPtr.Zero,false,0x08000004,IntPtr.Zero,cwd,ref startup,out child)){CloseHandle(job);job=IntPtr.Zero;throw new Exception("PHP worker startup failed");}
        process=child.process;
        if(!AssignProcessToJobObject(job,process)){TerminateProcess(process,1);CloseHandle(child.thread);CloseHandle(process);CloseHandle(job);process=job=IntPtr.Zero;throw new Exception("PHP process supervision unavailable");}
        ResumeThread(child.thread);CloseHandle(child.thread);
        watcher=new Timer(delegate(object state){if(process!=IntPtr.Zero&&WaitForSingleObject(process,0)==0){ExitCode=1;Stop();}},null,1000,1000);
    }
    protected override void OnStop(){if(watcher!=null)watcher.Dispose();if(job!=IntPtr.Zero){CloseHandle(job);job=IntPtr.Zero;}if(process!=IntPtr.Zero){CloseHandle(process);process=IntPtr.Zero;}}
    public static void Main(){ServiceBase.Run(new NmsSshService());}
}
