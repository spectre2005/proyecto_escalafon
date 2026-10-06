export default function FormSplit({ children, pdf }) {
  return <div className="col-span-full grid min-w-0 gap-6 min-[901px]:grid-cols-2">
    <div className="grid min-w-0 content-start grid-cols-1 gap-4 min-[521px]:grid-cols-2 [&>.sm\:col-span-2]:col-span-full">{children}</div>
    <div className="min-w-0">{pdf}</div>
  </div>
}
