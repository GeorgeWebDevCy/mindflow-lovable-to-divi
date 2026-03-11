import { Mail, Phone, MapPin } from "lucide-react";

const Footer = () => {
  const currentYear = new Date().getFullYear();

  const scrollTo = (id: string) => {
    document.querySelector(id)?.scrollIntoView({ behavior: "smooth" });
  };

  return (
    <footer className="bg-primary text-primary-foreground">
      <div className="container mx-auto px-6 lg:px-8 py-16">
        <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-10">
          {/* Brand */}
          <div className="lg:col-span-2">
            <a href="#home" className="font-heading text-xl font-bold tracking-tight">
              Digital<span className="text-gradient-accent">MindFlow</span>
            </a>
            <p className="mt-4 text-sm text-primary-foreground/60 max-w-md leading-relaxed">
              A studio offering strategic, modern and effective digital marketing
              solutions for businesses that want to grow their online presence.
            </p>
          </div>

          {/* Quick Links */}
          <div>
            <h4 className="font-heading text-sm font-semibold uppercase tracking-wider mb-4 text-primary-foreground/80">
              Quick Links
            </h4>
            <ul className="space-y-2.5">
              {["About", "Services", "Process", "Contact"].map((label) => (
                <li key={label}>
                  <button
                    onClick={() => scrollTo(`#${label.toLowerCase()}`)}
                    className="text-sm text-primary-foreground/60 hover:text-accent transition-colors"
                  >
                    {label}
                  </button>
                </li>
              ))}
            </ul>
          </div>

          {/* Contact */}
          <div>
            <h4 className="font-heading text-sm font-semibold uppercase tracking-wider mb-4 text-primary-foreground/80">
              Contact
            </h4>
            <ul className="space-y-3">
              <li>
                <a
                  href="mailto:info@mindflowdigital.com"
                  className="flex items-center gap-2 text-sm text-primary-foreground/60 hover:text-accent transition-colors"
                >
                  <Mail className="w-4 h-4" />
                  info@mindflowdigital.com
                </a>
              </li>
              <li>
                <a
                  href="tel:+35799882116"
                  className="flex items-center gap-2 text-sm text-primary-foreground/60 hover:text-accent transition-colors"
                >
                  <Phone className="w-4 h-4" />
                  +357 99 882116
                </a>
              </li>
              <li>
                <span className="flex items-center gap-2 text-sm text-primary-foreground/60">
                  <MapPin className="w-4 h-4" />
                  Paphos, Cyprus
                </span>
              </li>
            </ul>
          </div>
        </div>

        {/* Divider */}
        <div className="mt-12 pt-8 border-t border-primary-foreground/10 flex flex-col sm:flex-row items-center justify-between gap-4">
          <p className="text-xs text-primary-foreground/40">
            © {currentYear} Digital MindFlow. All rights reserved.
          </p>
          <p className="text-xs text-primary-foreground/40">
            Marketing Services · Paphos, Cyprus
          </p>
        </div>
      </div>
    </footer>
  );
};

export default Footer;
